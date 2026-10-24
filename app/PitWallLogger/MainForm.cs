// The window and tray icon. Closing the window keeps the logger running in the tray (like Crew
// Chief); Quit from the tray menu stops it. The window only redraws while it's visible.
using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.Drawing;
using System.Drawing.Drawing2D;
using System.IO;
using System.Linq;
using System.Net;
using System.Net.Sockets;
using System.Runtime.InteropServices;
using System.Threading.Tasks;
using System.Windows.Forms;

namespace PitWall
{
    sealed class MainForm : Form
    {
        static readonly Color Green = Color.FromArgb(22, 163, 74), Amber = Color.FromArgb(217, 119, 6), Blue = Color.FromArgb(37, 99, 235),
            Grey = Color.FromArgb(120, 120, 120), Ink = Color.FromArgb(20, 20, 20), Paper = Color.FromArgb(250, 250, 248);

        readonly Engine engine;
        readonly Settings settings;
        readonly Uploader uploader;     // null when the Pit Wall folder wasn't found
        readonly NotifyIcon tray = new NotifyIcon();
        readonly Dictionary<Color, Icon> icons = new Dictionary<Color, Icon>();
        readonly Timer uiTimer = new Timer { Interval = 500 }, queueTimer = new Timer { Interval = 2000 }, watcherTimer = new Timer { Interval = 30000 };
        FileSystemWatcher fsw;
        bool quitting, uploading, startHidden;
        string lastStateKey = "";

        readonly Panel dot = new Panel { Width = 18, Height = 18, Margin = new Padding(0, 6, 8, 0) };
        readonly Label lblStatus = new Label { AutoSize = true, Font = new Font("Segoe UI", 13f, FontStyle.Bold) };
        readonly Label lblSim = new Label { AutoSize = true, ForeColor = Grey };
        readonly Label lblRates = new Label { AutoSize = true, ForeColor = Grey };
        readonly Label badge = new Label { Dock = DockStyle.Fill, Height = 30, TextAlign = ContentAlignment.MiddleLeft, Padding = new Padding(8, 0, 0, 0), Font = new Font("Segoe UI", 10f, FontStyle.Bold), ForeColor = Color.White };
        readonly Label lblLogged = new Label { AutoSize = true };
        readonly ListView list = new ListView { Dock = DockStyle.Fill, View = View.Details, FullRowSelect = true, HeaderStyle = ColumnHeaderStyle.Nonclickable, BorderStyle = BorderStyle.FixedSingle };
        readonly CheckBox chkAfterRace = new CheckBox { AutoSize = true, Text = "Upload automatically when a race finishes" };
        readonly CheckBox chkNewFiles = new CheckBox { AutoSize = true, Text = "Upload new files in data\\ as they arrive" };
        readonly Label lblQueue = new Label { AutoSize = true, Margin = new Padding(3, 8, 3, 0) };
        readonly Button btnUpload = new Button { Text = "Upload now", AutoSize = true };
        readonly Button btnFolder = new Button { Text = "Open live logs", AutoSize = true };
        readonly Label lblSite = new Label { AutoSize = true, ForeColor = Grey };
        readonly Label lblWatcher = new Label { AutoSize = true, ForeColor = Amber };
        readonly CheckBox chkStartup = new CheckBox { AutoSize = true, Text = "Start with Windows (in the tray)" };
        readonly ComboBox cmbHz = new ComboBox { DropDownStyle = ComboBoxStyle.DropDownList, Width = 150 };
        readonly Button btnPause = new Button { Text = "Pause logging", AutoSize = true };
        readonly TextBox txtLog = new TextBox { Dock = DockStyle.Fill, Multiline = true, ReadOnly = true, ScrollBars = ScrollBars.Vertical, BackColor = Color.White, Font = new Font("Consolas", 8.5f), BorderStyle = BorderStyle.FixedSingle };
        static readonly int[] HzChoices = { 60, 20, 10, 5 };

        public MainForm(Engine engine, Settings settings, Uploader uploader, bool startHidden)
        {
            this.engine = engine; this.settings = settings; this.uploader = uploader; this.startHidden = startHidden;
            Text = "Pit Wall Logger";
            Font = new Font("Segoe UI", 9f);
            BackColor = Paper; ForeColor = Ink;
            ClientSize = new Size(560, 640);
            MinimumSize = new Size(480, 520);
            try { Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath); } catch { }
            BuildLayout();
            BuildTray();

            engine.OnLog += s => Post(() => AddLog(s));
            engine.OnIncident += e => Post(() => AddIncident(e));
            engine.OnSaved += (file, race) => { if (race && settings.UploadAfterRace) Upload(file); };

            chkAfterRace.Checked = settings.UploadAfterRace;
            chkNewFiles.Checked = settings.UploadNewFiles;
            chkAfterRace.CheckedChanged += (s, e) => { settings.UploadAfterRace = chkAfterRace.Checked; settings.Save(); };
            chkNewFiles.CheckedChanged += (s, e) => { settings.UploadNewFiles = chkNewFiles.Checked; settings.Save(); if (chkNewFiles.Checked) queueTimer.Start(); };
            try { chkStartup.Checked = Settings.StartsWithWindows; } catch { chkStartup.Enabled = false; }
            chkStartup.CheckedChanged += (s, e) => { try { Settings.StartsWithWindows = chkStartup.Checked; } catch (Exception ex) { AddLog("Couldn't change the startup entry: " + ex.Message); } };
            foreach (var hz in HzChoices) cmbHz.Items.Add(hz == 60 ? "Every tick (60/s)" : hz + " reads/s");
            cmbHz.SelectedIndex = Math.Max(0, Array.IndexOf(HzChoices, settings.Hz));
            cmbHz.SelectedIndexChanged += (s, e) => { settings.Hz = engine.Hz = HzChoices[cmbHz.SelectedIndex]; settings.Save(); engine.Poke(); };
            btnPause.Click += (s, e) => TogglePause();
            btnUpload.Click += (s, e) => Upload(null);
            btnFolder.Click += (s, e) => { Directory.CreateDirectory(engine.OutDir); Process.Start("explorer.exe", "\"" + engine.OutDir + "\""); };

            uiTimer.Tick += (s, e) => RefreshState();
            queueTimer.Tick += (s, e) => { queueTimer.Stop(); RefreshQueue(settings.UploadNewFiles); };
            watcherTimer.Tick += (s, e) => CheckOldWatcher();
            uiTimer.Start(); watcherTimer.Start();

            if (uploader != null)
            {
                lblSite.Text = uploader.Configured ? "Site: " + uploader.Site : "Site: not set up (PITWALL_SITE and PITWALL_API_KEY in .env)";
                if (Directory.Exists(uploader.DataDir))
                {
                    fsw = new FileSystemWatcher(uploader.DataDir, "*.json") { IncludeSubdirectories = true, NotifyFilter = NotifyFilters.FileName | NotifyFilters.LastWrite | NotifyFilters.Size };
                    FileSystemEventHandler changed = (s, e) => Post(() => { queueTimer.Stop(); queueTimer.Start(); });
                    fsw.Created += changed; fsw.Changed += changed; fsw.Renamed += (s, e) => changed(s, e);
                    fsw.EnableRaisingEvents = true;
                }
            }
            else
            {
                lblSite.Text = "Pit Wall folder not found: uploads are off";
                btnUpload.Enabled = chkAfterRace.Enabled = chkNewFiles.Enabled = false;
            }
            CheckOldWatcher();
            RefreshQueue(false);
            RefreshState();
        }

        // ---------------------------------------------------------------- layout
        void BuildLayout()
        {
            var root = new TableLayoutPanel { Dock = DockStyle.Fill, ColumnCount = 1, Padding = new Padding(14), RowCount = 7 };
            root.ColumnStyles.Add(new ColumnStyle(SizeType.Percent, 100));   // never wider than the window
            root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
            root.RowStyles.Add(new RowStyle(SizeType.Absolute, 36));
            root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
            root.RowStyles.Add(new RowStyle(SizeType.Percent, 55));
            root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
            root.RowStyles.Add(new RowStyle(SizeType.AutoSize));
            root.RowStyles.Add(new RowStyle(SizeType.Percent, 45));

            dot.Paint += (s, e) =>
            {
                e.Graphics.SmoothingMode = SmoothingMode.AntiAlias;
                using (var b = new SolidBrush((Color)(dot.Tag ?? Grey))) e.Graphics.FillEllipse(b, 1, 1, 15, 15);
            };
            var head = new FlowLayoutPanel { AutoSize = true, FlowDirection = FlowDirection.LeftToRight, WrapContents = false, Dock = DockStyle.Fill };
            var lines = new FlowLayoutPanel { AutoSize = true, FlowDirection = FlowDirection.TopDown, WrapContents = false };
            lines.Controls.AddRange(new Control[] { lblStatus, lblSim, lblRates });
            head.Controls.AddRange(new Control[] { dot, lines });
            // long lines wrap instead of widening the window
            head.SizeChanged += (s, e) => { var w = new Size(Math.Max(200, root.ClientSize.Width - 70), 0); lblSim.MaximumSize = lblRates.MaximumSize = lblStatus.MaximumSize = w; };
            lblLogged.AutoSize = false; lblLogged.Dock = DockStyle.Fill; lblLogged.AutoEllipsis = true; lblLogged.Height = 22;
            root.Controls.Add(head);

            badge.Margin = new Padding(0, 6, 0, 0);
            root.Controls.Add(badge);
            lblLogged.Margin = new Padding(0, 8, 0, 4);
            root.Controls.Add(lblLogged);

            list.Columns.Add("Time", 50); list.Columns.Add("Lap", 36); list.Columns.Add("Where", 56); list.Columns.Add("Driver", 175); list.Columns.Add("What", 175);
            root.Controls.Add(list);

            var up = new GroupBox { Text = "Uploads", Dock = DockStyle.Fill, AutoSize = true, Margin = new Padding(0, 10, 0, 0), Padding = new Padding(8) };
            var upFlow = new FlowLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, FlowDirection = FlowDirection.TopDown, WrapContents = false };
            lblSite.MaximumSize = lblWatcher.MaximumSize = new Size(480, 0);
            var row = new FlowLayoutPanel { AutoSize = true, WrapContents = false, Margin = new Padding(0, 4, 0, 0) };
            row.Controls.AddRange(new Control[] { btnUpload, btnFolder, lblQueue });
            upFlow.Controls.AddRange(new Control[] { chkAfterRace, chkNewFiles, row, lblSite, lblWatcher });
            up.Controls.Add(upFlow);
            root.Controls.Add(up);

            var opts = new FlowLayoutPanel { AutoSize = true, WrapContents = true, Dock = DockStyle.Fill, Margin = new Padding(0, 6, 0, 4) };
            var lblHz = new Label { Text = "Telemetry:", AutoSize = true, Margin = new Padding(12, 7, 0, 0) };
            opts.Controls.AddRange(new Control[] { btnPause, lblHz, cmbHz, chkStartup });
            chkStartup.Margin = new Padding(12, 5, 0, 0);
            root.Controls.Add(opts);
            root.Controls.Add(txtLog);
            Controls.Add(root);
        }

        void BuildTray()
        {
            tray.Text = "Pit Wall Logger";
            tray.Icon = IconFor(Grey);
            var menu = new ContextMenuStrip();
            menu.Items.Add("Open Pit Wall Logger", null, (s, e) => ShowWindow());
            menu.Items.Add("Pause / resume logging", null, (s, e) => TogglePause());
            menu.Items.Add("Upload now", null, (s, e) => Upload(null));
            menu.Items.Add(new ToolStripSeparator());
            menu.Items.Add("Quit", null, (s, e) => Quit());
            tray.ContextMenuStrip = menu;
            tray.DoubleClick += (s, e) => ShowWindow();
            tray.Visible = true;
        }

        Icon IconFor(Color c)
        {
            Icon ic;
            if (icons.TryGetValue(c, out ic)) return ic;
            using (var bmp = new Bitmap(16, 16))
            {
                using (var g = Graphics.FromImage(bmp))
                {
                    g.SmoothingMode = SmoothingMode.AntiAlias;
                    using (var b = new SolidBrush(c)) g.FillEllipse(b, 1, 1, 14, 14);
                    using (var p = new Pen(Color.FromArgb(200, 255, 255, 255), 1.5f)) g.DrawEllipse(p, 1, 1, 14, 14);
                }
                ic = Icon.FromHandle(bmp.GetHicon());
            }
            icons[c] = ic;
            return ic;
        }

        // ---------------------------------------------------------------- window / tray behaviour
        /// <summary>Show without taking focus (for --screenshot, so a running game keeps it).</summary>
        public bool NoActivate;
        protected override bool ShowWithoutActivation { get { return NoActivate; } }

        protected override void SetVisibleCore(bool value)
        {
            bool trim = false;
            if (startHidden) { startHidden = false; value = false; trim = true; if (!IsHandleCreated) CreateHandle(); }
            base.SetVisibleCore(value);
            if (trim) BeginInvoke(new Action(TrimMemory));
        }

        protected override void OnFormClosing(FormClosingEventArgs e)
        {
            if (!quitting && e.CloseReason == CloseReason.UserClosing)
            {
                e.Cancel = true;
                HideToTray();
                return;
            }
            tray.Visible = false;
            base.OnFormClosing(e);
        }

        protected override void OnResize(EventArgs e)
        {
            base.OnResize(e);
            if (WindowState == FormWindowState.Minimized) HideToTray();
        }

        [DllImport("kernel32")] static extern bool SetProcessWorkingSetSize(IntPtr proc, IntPtr min, IntPtr max);
        void HideToTray()
        {
            Hide();
            TrimMemory();
        }

        /// <summary>Give back the window's memory while it sits in the tray.</summary>
        static void TrimMemory()
        {
            try { GC.Collect(); SetProcessWorkingSetSize(Process.GetCurrentProcess().Handle, (IntPtr)(-1), (IntPtr)(-1)); } catch { }
        }

        public void Quit() { quitting = true; Close(); }

        public void ShowWindow()
        {
            Show();
            if (WindowState == FormWindowState.Minimized) WindowState = FormWindowState.Normal;
            Activate();
            lastStateKey = "";
            RefreshState();
        }

        void TogglePause()
        {
            engine.Paused = !engine.Paused;
            engine.Poke();
            btnPause.Text = engine.Paused ? "Resume logging" : "Pause logging";
            AddLog(engine.Paused ? "Logging paused." : "Logging resumed.");
        }

        void Post(Action a)
        {
            try { if (IsHandleCreated && !IsDisposed) BeginInvoke(a); } catch (InvalidOperationException) { }
        }

        // ---------------------------------------------------------------- state
        void RefreshState()
        {
            var s = engine.State;
            Color c; string head;
            if (s.Paused || engine.Paused) { c = Grey; head = "Paused"; }
            else if (!s.Sim) { c = Grey; head = "Running · waiting for iRacing"; }
            else if (!s.Live) { c = Blue; head = "Running · " + s.Status; }
            else if (s.View == ViewKind.NotAdmin) { c = Amber; head = "Running · " + s.Status; }
            else { c = Green; head = "Running · " + s.Status; }

            string key = head + "|" + c.ToArgb() + "|" + s.Track + s.SessionType + s.Incidents + s.ViewText + (int)s.TickHz + "|" + s.InfoGapS.ToString("0.0") + s.LastIncident;
            tray.Icon = IconFor(c);
            string tip = "Pit Wall Logger: " + head + (s.Live ? " · " + s.Incidents + " incidents" : "");
            tray.Text = tip.Length > 63 ? tip.Substring(0, 63) : tip;
            if (!Visible || key == lastStateKey) return;
            lastStateKey = key;

            dot.Tag = c; dot.Invalidate();
            lblStatus.Text = head;
            lblSim.Text = !s.Sim ? "iRacing isn't running. The logger checks every few seconds and uses no CPU meanwhile."
                : (s.Track.Length > 0 ? s.Track : "iRacing") + (s.SessionType.Length > 0 ? " · " + s.SessionType : "") + (s.Sub > 0 ? " · subsession " + s.Sub : "") + (s.Me.Length > 0 ? " · you: " + s.Me : "");
            lblRates.Text = s.Live ? string.Format("Telemetry {0:0}/s · session info every {1:0.0} s (parse {2:0.0} ms)", s.TickHz, s.InfoGapS, s.ParseMs) : "";
            switch (s.View)
            {
                case ViewKind.Admin: badge.BackColor = Green; break;
                case ViewKind.Everyone: badge.BackColor = Blue; break;
                case ViewKind.NotAdmin: badge.BackColor = Amber; break;
                case ViewKind.Checking: badge.BackColor = Grey; break;
                default: badge.BackColor = Color.FromArgb(200, 200, 200); break;
            }
            badge.Text = s.View == ViewKind.None ? "View: not in a session" : (s.View == ViewKind.Admin ? "■ ADMIN VIEW · " : s.View == ViewKind.NotAdmin ? "■ NOT ADMIN · " : "") + s.ViewText;
            lblLogged.Text = s.Live || s.Incidents > 0 ? "Logged: " + s.Incidents + " incident" + (s.Incidents == 1 ? "" : "s") + (s.LastIncident.Length > 0 ? " · last: " + s.LastIncident : "") : "Logged: nothing yet";
        }

        void AddIncident(Incident e)
        {
            var w = e.Where;
            var item = new ListViewItem(new[] {
                string.Format("{0}:{1:00}", (int)(e.T / 60), (int)(e.T % 60)),
                w == null ? "" : w.Lap.ToString(),
                w == null ? "" : (w.Pct * 100).ToString("0.0") + "%",
                (string.IsNullOrEmpty(e.Number) ? "" : "#" + e.Number + " ") + e.Name,
                e.Kind + " (" + e.From + "→" + e.To + ")" });
            if (e.Off == null) item.ForeColor = Grey;  // no off-track seen: placed where the car was when the count rose
            list.Items.Insert(0, item);
            while (list.Items.Count > 300) list.Items.RemoveAt(list.Items.Count - 1);
        }

        void AddLog(string s)
        {
            string line = DateTime.Now.ToString("HH:mm:ss") + "  " + s + Environment.NewLine;
            if (txtLog.TextLength > 60000) txtLog.Text = txtLog.Text.Substring(txtLog.TextLength - 30000);
            txtLog.AppendText(line);
            try { File.AppendAllText(Path.Combine(engine.OutDir, "logger.log"), line); } catch { }
        }

        // ---------------------------------------------------------------- uploads
        void RefreshQueue(bool thenUpload)
        {
            if (uploader == null) return;
            Task.Run(() =>
            {
                var pending = uploader.Pending();
                Post(() =>
                {
                    lblQueue.Text = !uploader.Configured ? "" : pending.Count == 0 ? "Everything is uploaded" : pending.Count + " file" + (pending.Count == 1 ? "" : "s") + " waiting";
                    if (thenUpload && pending.Count > 0) Upload(null);
                });
            });
        }

        /// <summary>Upload a live log (if given) and everything waiting in data/.</summary>
        void Upload(string liveFile)
        {
            if (uploader == null) return;
            Post(() =>
            {
                if (uploading) { AddLog("An upload is already running."); return; }
                uploading = true; btnUpload.Enabled = false;
                AddLog(liveFile != null ? "Race finished: uploading…" : "Uploading…");
                Task.Run(() =>
                {
                    try
                    {
                        uploader.Reload();
                        Action<string> log = s => Post(() => AddLog("  " + s));
                        if (liveFile != null) uploader.PushLive(liveFile, log);
                        var r = uploader.Push(uploader.Pending(), log);
                        Post(() => AddLog(r[0] + r[1] == 0 ? "Nothing new in data\\ to upload." : r[0] + " sent, " + r[1] + " failed."));
                    }
                    catch (Exception e) { Post(() => AddLog("Upload failed: " + e.Message)); }
                    finally { Post(() => { uploading = false; btnUpload.Enabled = true; RefreshQueue(false); }); }
                });
            });
        }

        /// <summary>The old Node watcher holds 127.0.0.1:48766 as its lock while it runs.</summary>
        void CheckOldWatcher()
        {
            bool running = false;
            try { var l = new TcpListener(IPAddress.Loopback, 48766); l.Start(); l.Stop(); }
            catch (SocketException) { running = true; }
            lblWatcher.Text = running ? "The old background watcher (Node) is also running and uploads new files by itself." : "";
            lblWatcher.Visible = running;
        }
    }
}
