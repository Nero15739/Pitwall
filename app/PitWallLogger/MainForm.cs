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
        readonly Uploader uploader;
        readonly NotifyIcon tray = new NotifyIcon();
        readonly Dictionary<Color, Icon> icons = new Dictionary<Color, Icon>();
        readonly Timer uiTimer = new Timer { Interval = 500 }, queueTimer = new Timer { Interval = 2000 }, watcherTimer = new Timer { Interval = 30000 };
        FileSystemWatcher fsw;
        bool quitting, uploading, uploadAgain, startHidden;
        string lastStateKey = "";
        SiteStatus siteStatus;          // last answer from the site: which replays it still needs
        string siteError;

        readonly Panel dot = new Panel { Width = 18, Height = 18, Margin = new Padding(0, 6, 8, 0) };
        readonly Label lblStatus = new Label { AutoSize = true, Font = new Font("Segoe UI", 13f, FontStyle.Bold) };
        readonly Label lblSim = new Label { AutoSize = true, ForeColor = Grey };
        readonly Label lblRates = new Label { AutoSize = true, ForeColor = Grey };
        readonly Label badge = new Label { Dock = DockStyle.Fill, Height = 30, TextAlign = ContentAlignment.MiddleLeft, Padding = new Padding(8, 0, 0, 0), Font = new Font("Segoe UI", 10f, FontStyle.Bold), ForeColor = Color.White };
        readonly Label lblLogged = new Label { AutoSize = true };
        readonly ListView list = new ListView { Dock = DockStyle.Fill, View = View.Details, FullRowSelect = true, HeaderStyle = ColumnHeaderStyle.Nonclickable, BorderStyle = BorderStyle.FixedSingle };
        readonly Label lblReplay = new Label { AutoSize = true };
        readonly Label lblToHarvest = new Label { AutoSize = true, ForeColor = Grey };
        readonly Button btnHarvest = new Button { Text = "Harvest this replay", AutoSize = true };
        readonly Button btnStopHarvest = new Button { Text = "Stop harvest", AutoSize = true, Visible = false };
        string siteText = "", queueText = "";
        readonly Button btnPause = new Button { Text = "Pause logging", AutoSize = true };
        readonly Button btnUpload = new Button { Text = "Upload now", AutoSize = true };
        readonly Button btnFolder = new Button { Text = "Open folder", AutoSize = true };
        readonly Button btnSettings = new Button { Text = "Settings…", AutoSize = true };
        readonly Label lblSite = new Label { AutoSize = true, ForeColor = Grey };
        readonly Label lblWatcher = new Label { AutoSize = true, ForeColor = Amber };
        readonly TextBox txtLog = new TextBox { Dock = DockStyle.Fill, Multiline = true, ReadOnly = true, ScrollBars = ScrollBars.Vertical, BackColor = Color.White, Font = new Font("Consolas", 8.5f), BorderStyle = BorderStyle.FixedSingle };

        public MainForm(Engine engine, Settings settings, Uploader uploader, bool startHidden)
        {
            this.engine = engine; this.settings = settings; this.uploader = uploader; this.startHidden = startHidden;
            Text = "Pit Wall Logger";
            Font = new Font("Segoe UI", 9f);
            BackColor = Paper; ForeColor = Ink;
            ClientSize = new Size(580, 680);
            MinimumSize = new Size(500, 560);
            try { Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath); } catch { }
            BuildLayout();
            BuildTray();

            engine.OnLog += s => Post(() => AddLog(s));
            engine.OnDetail += s => WriteLogFile(s);
            engine.OnIncident += e => Post(() => AddIncident(e));
            engine.OnSaved += (file, race) => { if (race && settings.UploadAfterRace) Upload("Race finished: uploading…"); };
            engine.OnReplay += r => Post(() => ReplayOpened(r));
            engine.OnHarvested += file => { if (uploader.Configured) Upload("Uploading the harvest…"); else Post(() => AddLog("Not uploaded: set the site address and API key in Settings, then Upload now.")); };

            btnPause.Click += (s, e) => TogglePause();
            btnUpload.Click += (s, e) => Upload(null);
            btnFolder.Click += (s, e) => { Directory.CreateDirectory(uploader.Paths.Data); Process.Start("explorer.exe", "\"" + uploader.Paths.Data + "\""); };
            btnSettings.Click += (s, e) => OpenSettings();
            btnHarvest.Click += (s, e) => HarvestClicked();
            btnStopHarvest.Click += (s, e) => { engine.StopHarvest(); AddLog("Stopping the harvest…"); };

            uiTimer.Tick += (s, e) => RefreshState();
            queueTimer.Tick += (s, e) => { queueTimer.Stop(); RefreshQueue(settings.UploadNewFiles); };
            watcherTimer.Tick += (s, e) => CheckOldWatcher();
            uiTimer.Start(); watcherTimer.Start();

            if (Directory.Exists(uploader.Paths.Data))
            {
                fsw = new FileSystemWatcher(uploader.Paths.Data, "*.json") { IncludeSubdirectories = true, NotifyFilter = NotifyFilters.FileName | NotifyFilters.LastWrite | NotifyFilters.Size };
                FileSystemEventHandler changed = (s, e) => Post(() => { queueTimer.Stop(); queueTimer.Start(); });
                fsw.Created += changed; fsw.Changed += changed; fsw.Renamed += (s, e) => changed(s, e);
                fsw.EnableRaisingEvents = true;
            }
            SiteChanged();
            CheckOldWatcher();
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
            head.SizeChanged += (s, e) =>
            {
                var w = new Size(Math.Max(200, root.ClientSize.Width - 70), 0);
                lblSim.MaximumSize = lblRates.MaximumSize = lblStatus.MaximumSize = w;
                lblReplay.MaximumSize = lblToHarvest.MaximumSize = lblSite.MaximumSize = lblWatcher.MaximumSize = new Size(Math.Max(200, root.ClientSize.Width - 50), 0);
            };
            lblLogged.AutoSize = false; lblLogged.Dock = DockStyle.Fill; lblLogged.AutoEllipsis = true; lblLogged.Height = 22;
            root.Controls.Add(head);

            badge.Margin = new Padding(0, 6, 0, 0);
            root.Controls.Add(badge);
            lblLogged.Margin = new Padding(0, 8, 0, 4);
            root.Controls.Add(lblLogged);

            list.Columns.Add("Time", 50); list.Columns.Add("Lap", 36); list.Columns.Add("Where", 56); list.Columns.Add("Driver", 175); list.Columns.Add("What", 175);
            root.Controls.Add(list);

            var rep = new GroupBox { Text = "Replays", Dock = DockStyle.Fill, AutoSize = true, Margin = new Padding(0, 10, 0, 0), Padding = new Padding(8) };
            var repFlow = new FlowLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, FlowDirection = FlowDirection.TopDown, WrapContents = false };
            var repRow = new FlowLayoutPanel { AutoSize = true, WrapContents = false, Margin = new Padding(0, 4, 0, 0) };
            repRow.Controls.AddRange(new Control[] { btnHarvest, btnStopHarvest });
            repFlow.Controls.AddRange(new Control[] { lblReplay, lblToHarvest, repRow });
            rep.Controls.Add(repFlow);
            root.Controls.Add(rep);

            var actions = new FlowLayoutPanel { Dock = DockStyle.Fill, AutoSize = true, FlowDirection = FlowDirection.TopDown, WrapContents = false, Margin = new Padding(0, 8, 0, 4) };
            var row = new FlowLayoutPanel { AutoSize = true, WrapContents = true };
            row.Controls.AddRange(new Control[] { btnPause, btnUpload, btnFolder, btnSettings });
            actions.Controls.AddRange(new Control[] { row, lblSite, lblWatcher });
            root.Controls.Add(actions);
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
            menu.Items.Add("Settings…", null, (s, e) => { ShowWindow(); OpenSettings(); });
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

        /// <summary>The Settings window; returns true when it was saved.</summary>
        public bool OpenSettings()
        {
            using (var f = new SettingsForm(settings, uploader))
            {
                if (f.ShowDialog(this) != DialogResult.OK) return false;
            }
            engine.Hz = settings.Hz;
            engine.Poke();
            AddLog("Settings saved.");
            SiteChanged();
            if (settings.UploadNewFiles) RefreshQueue(true);
            return true;
        }

        // ---------------------------------------------------------------- state
        void RefreshState()
        {
            var s = engine.State;
            Color c; string head;
            if (s.Harvesting) { c = Blue; head = "Harvesting replay"; }
            else if (s.Paused || engine.Paused) { c = Grey; head = "Paused"; }
            else if (!s.Sim) { c = Grey; head = "Running · waiting for iRacing"; }
            else if (!s.Live) { c = Blue; head = "Running · " + s.Status; }
            else if (s.View == ViewKind.NotAdmin) { c = Amber; head = "Running · " + s.Status; }
            else { c = Green; head = "Running · " + s.Status; }

            var r = s.Replay;
            string key = head + "|" + c.ToArgb() + "|" + s.Track + s.SessionType + s.Incidents + s.ViewText + (int)s.TickHz + "|" + s.InfoGapS.ToString("0.0") + s.LastIncident
                + "|" + (r == null ? "" : r.Sub + "" + r.Harvested) + s.Harvesting + s.HarvestProgress;
            tray.Icon = IconFor(c);
            string tip = "Pit Wall Logger: " + head + (s.Harvesting ? " · " + s.HarvestProgress : s.Live ? " · " + s.Incidents + " incidents" : "");
            tray.Text = tip.Length > 63 ? tip.Substring(0, 63) : tip;
            if (!Visible || key == lastStateKey) return;
            lastStateKey = key;

            dot.Tag = c; dot.Invalidate();
            lblStatus.Text = head;
            lblSim.Text = !s.Sim ? "iRacing isn't running. The logger checks every few seconds and uses no CPU meanwhile."
                : (s.Track.Length > 0 ? s.Track : "iRacing") + (s.SessionType.Length > 0 ? " · " + s.SessionType : "") + (s.Sub > 0 ? " · subsession " + s.Sub : "") + (s.Me.Length > 0 && r == null ? " · you: " + s.Me : "");
            lblRates.Text = s.Live ? string.Format("Telemetry {0:0}/s · session info every {1:0.0} s (parse {2:0.0} ms)", s.TickHz, s.InfoGapS, s.ParseMs) : "";
            switch (s.View)
            {
                case ViewKind.Admin: badge.BackColor = Green; break;
                case ViewKind.Everyone: badge.BackColor = Blue; break;
                case ViewKind.NotAdmin: badge.BackColor = Amber; break;
                case ViewKind.Checking: badge.BackColor = Grey; break;
                default: badge.BackColor = Color.FromArgb(200, 200, 200); break;
            }
            badge.Text = r != null ? "Replay: nothing to log live" : s.View == ViewKind.None ? "View: not in a session" : (s.View == ViewKind.Admin ? "■ ADMIN VIEW · " : s.View == ViewKind.NotAdmin ? "■ NOT ADMIN · " : "") + s.ViewText;
            lblLogged.Text = s.Live || s.Incidents > 0 ? "Logged: " + s.Incidents + " incident" + (s.Incidents == 1 ? "" : "s") + (s.LastIncident.Length > 0 ? " · last: " + s.LastIncident : "") : "Logged: nothing yet";

            // replays
            if (s.Harvesting) lblReplay.Text = "Harvesting " + (r != null ? r.Track + " (subsession " + r.Sub + ")" : "the replay") + ": " + s.HarvestProgress + ". Leave iRacing alone until it finishes.";
            else if (r != null) lblReplay.Text = "Open: " + r.Track + (r.Sub > 0 ? ", subsession " + r.Sub : "") + " · " + (!r.HasRace ? "no race in this replay" : r.Harvested ? "harvested" : "not harvested yet");
            else if (s.Sim) lblReplay.Text = "No replay open (iRacing is in a live session).";
            else lblReplay.Text = "To harvest a race (crash spots, sector times, lap chart), launch its replay from the iRacing app (Replays).";
            btnHarvest.Text = r != null && r.Harvested ? "Harvest again" : "Harvest this replay";
            btnHarvest.Enabled = r != null && r.HasRace && r.Sub > 0 && !s.Harvesting;
            btnStopHarvest.Visible = s.Harvesting;
            ShowToHarvest();
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
            WriteLogFile(s);
        }

        /// <summary>logger.log beside the live logs: everything, including the harvester's step-by-step detail.</summary>
        void WriteLogFile(string s)
        {
            try { Directory.CreateDirectory(engine.OutDir); File.AppendAllText(Path.Combine(engine.OutDir, "logger.log"), DateTime.Now.ToString("HH:mm:ss") + "  " + s + Environment.NewLine); } catch { }
        }

        // ---------------------------------------------------------------- replays
        /// <summary>A replay was opened: harvest it straight away if it's a league race the site still needs.</summary>
        void ReplayOpened(ReplayInfo r)
        {
            if (!r.HasRace || r.Sub <= 0 || r.Harvested) return;
            if (!settings.AutoHarvest || !uploader.Configured)
            {
                AddLog("Click \"Harvest this replay\" to harvest it" + (settings.AutoHarvest ? " (with the site set up in Settings, league races are harvested by themselves)." : "."));
                return;
            }
            Task.Run(() =>
            {
                SiteStatus st = null; string err = null;
                try { st = uploader.Status(); } catch (Exception e) { err = e.Message; }
                Post(() =>
                {
                    if (st != null) { siteStatus = st; siteError = null; lastStateKey = ""; }
                    var now = engine.State;
                    if (now.Harvesting || (now.Replay != null && now.Replay.Sub != r.Sub)) return;
                    var p = st == null ? null : st.Pending.FirstOrDefault(x => x.Sub == r.Sub);
                    if (p != null)
                    {
                        AddLog("The site still needs this race (" + p.Season + " R" + p.Round + ", " + p.Track + "): harvesting it now. Leave iRacing alone until it finishes.");
                        engine.Harvest(r.Sub, false);
                    }
                    else if (st != null) AddLog("This replay isn't a race the site is waiting for. Click \"Harvest this replay\" to harvest it anyway.");
                    else AddLog("Couldn't ask the site which races it needs (" + err + "). Click \"Harvest this replay\" to harvest this one.");
                });
            });
        }

        void HarvestClicked()
        {
            var r = engine.State.Replay;
            if (r == null || !r.HasRace || r.Sub <= 0) return;
            bool force = false;
            if (r.Harvested)
            {
                if (MessageBox.Show(this, "Subsession " + r.Sub + " is already harvested. Harvest it again and replace the file?", "Harvest again?",
                    MessageBoxButtons.YesNo, MessageBoxIcon.Question) != DialogResult.Yes) return;
                force = true;
            }
            AddLog("Harvesting the replay. Leave iRacing alone until it finishes (about race length ÷ 16).");
            engine.Harvest(r.Sub, force);
        }

        /// <summary>Which replays the site still needs, marking the ones whose replay file is on this PC.</summary>
        void ShowToHarvest()
        {
            string text;
            if (!uploader.Configured) text = "Set the site address and API key in Settings to see which races still need their replay harvested.";
            else if (siteStatus == null) text = siteError != null ? "Couldn't ask the site which races need harvesting: " + siteError : "";
            else if (siteStatus.Pending.Count == 0) text = "Every race on the site has its replay harvested.";
            else
            {
                string dir = ReplayDir();
                text = "Still to harvest: " + string.Join(" · ", siteStatus.Pending.Take(5).Select(p => p.Season + " R" + p.Round + " " + p.Track
                    + (dir != null && File.Exists(Path.Combine(dir, "subses" + p.Sub + ".rpy")) ? " (replay on this PC)" : " (no replay here)")))
                    + (siteStatus.Pending.Count > 5 ? " and " + (siteStatus.Pending.Count - 5) + " more" : "");
            }
            if (lblToHarvest.Text != text) lblToHarvest.Text = text;
            lblToHarvest.Visible = text.Length > 0;
        }

        /// <summary>iRacing's replay folder (Documents is often redirected into OneDrive; MyDocuments follows that).</summary>
        static string ReplayDir()
        {
            var candidates = new[] { Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments), Environment.GetEnvironmentVariable("OneDrive") == null ? null : Path.Combine(Environment.GetEnvironmentVariable("OneDrive"), "Documents"),
                Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.UserProfile), "Documents") };
            return candidates.Where(d => d != null).Select(d => Path.Combine(d, "iRacing", "replay")).FirstOrDefault(Directory.Exists);
        }

        // ---------------------------------------------------------------- uploads
        /// <summary>The site address or key changed (or at start): show it and ask the site what it needs.</summary>
        void SiteChanged()
        {
            uploader.Reload();
            siteText = uploader.Configured ? "Site: " + uploader.Site : "Uploads are off: open Settings to add the site address and API key.";
            queueText = "";
            ShowSite();
            siteStatus = null; siteError = null; lastStateKey = "";
            RefreshQueue(false);
            RefreshSiteStatus();
        }

        void ShowSite() { lblSite.Text = siteText + (queueText.Length > 0 ? " · " + queueText : ""); }

        void RefreshSiteStatus()
        {
            if (!uploader.Configured) return;
            Task.Run(() =>
            {
                SiteStatus st = null; string err = null;
                try { st = uploader.Status(); } catch (Exception e) { err = e.Message; }
                Post(() => { if (st != null) siteStatus = st; siteError = err; lastStateKey = ""; RefreshState(); });
            });
        }

        void RefreshQueue(bool thenUpload)
        {
            Task.Run(() =>
            {
                var pending = uploader.Pending();
                Post(() =>
                {
                    queueText = !uploader.Configured ? "" : pending.Count == 0 ? "everything is uploaded" : pending.Count + " file" + (pending.Count == 1 ? "" : "s") + " waiting";
                    ShowSite();
                    // new files as they land: race results and harvests (a live log goes when its race finishes, or with Upload now)
                    if (thenUpload && pending.Any(f => !Uploader.IsLive(f))) Upload(null, true);
                });
            });
        }

        /// <summary>Upload everything waiting (skipLive: not the live logs). Asked again while one runs, it runs once more after.</summary>
        void Upload(string why, bool skipLive = false)
        {
            Post(() =>
            {
                if (uploading) { uploadAgain = true; return; }
                uploading = true; btnUpload.Enabled = false;
                AddLog(why ?? "Uploading…");
                Task.Run(() =>
                {
                    try
                    {
                        uploader.Reload();
                        Action<string> log = s => Post(() => AddLog("  " + s));
                        var r = uploader.Push(uploader.Pending().Where(f => !(skipLive && Uploader.IsLive(f))), log);
                        Post(() => AddLog(!uploader.Configured ? "Set the site address and API key in Settings to upload." : r[0] + r[1] == 0 ? "Nothing new to upload." : r[0] + " sent, " + r[1] + " failed."));
                    }
                    catch (Exception e) { Post(() => AddLog("Upload failed: " + e.Message)); }
                    finally
                    {
                        Post(() =>
                        {
                            uploading = false; btnUpload.Enabled = true;
                            RefreshQueue(false); RefreshSiteStatus();
                            if (uploadAgain) { uploadAgain = false; Upload(null); }
                        });
                    }
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
