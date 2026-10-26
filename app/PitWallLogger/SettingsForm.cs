// The Settings window: the Pit Wall site's address and API key (with a connection test), uploads,
// replay harvesting, and the logger's own options. Nothing changes until Save.
using System;
using System.Drawing;
using System.Linq;
using System.Threading.Tasks;
using System.Windows.Forms;

namespace PitWall
{
    sealed class SettingsForm : Form
    {
        static readonly Color Green = Color.FromArgb(22, 163, 74), Red = Color.FromArgb(185, 28, 28), Grey = Color.FromArgb(120, 120, 120);
        static readonly int[] HzChoices = { 60, 20, 10, 5 };

        readonly Settings settings;
        readonly Uploader uploader;
        readonly string[] fallback;     // what the Node tools would use: site, from, key, from

        readonly TextBox txtSite = new TextBox { Width = 440 };
        readonly TextBox txtKey = new TextBox { Width = 440, UseSystemPasswordChar = true };
        readonly CheckBox chkShow = new CheckBox { Text = "Show", AutoSize = true, Margin = new Padding(6, 5, 0, 0) };
        readonly Button btnTest = new Button { Text = "Test connection", AutoSize = true };
        readonly Label lblTest = new Label { AutoSize = true, Margin = new Padding(6, 8, 0, 0) };
        readonly CheckBox chkAfterRace = new CheckBox { AutoSize = true, Text = "Upload when a race finishes (its live log, and anything else waiting)" };
        readonly CheckBox chkNewFiles = new CheckBox { AutoSize = true, Text = "Upload new race results and harvests as they arrive" };
        readonly CheckBox chkAutoHarvest = new CheckBox { AutoSize = true, Text = "Harvest a league race's replay as soon as it's opened, if the site still needs it" };
        readonly ComboBox cmbHz = new ComboBox { DropDownStyle = ComboBoxStyle.DropDownList, Width = 150 };
        readonly CheckBox chkStartup = new CheckBox { AutoSize = true, Text = "Start with Windows (in the tray)" };

        public SettingsForm(Settings settings, Uploader uploader)
        {
            this.settings = settings; this.uploader = uploader;
            fallback = Uploader.Fallback(uploader.Paths.Root);
            Text = "Pit Wall Logger settings";
            Font = new Font("Segoe UI", 9f);
            BackColor = Color.FromArgb(250, 250, 248);
            FormBorderStyle = FormBorderStyle.FixedDialog;
            MaximizeBox = MinimizeBox = false;
            ShowInTaskbar = false;
            StartPosition = FormStartPosition.CenterParent;
            AutoSize = true; AutoSizeMode = AutoSizeMode.GrowAndShrink;
            try { Icon = Icon.ExtractAssociatedIcon(Application.ExecutablePath); } catch { }

            var root = new FlowLayoutPanel { FlowDirection = FlowDirection.TopDown, WrapContents = false, AutoSize = true, Padding = new Padding(12) };

            // ---- the site
            var site = Group("Pit Wall site");
            site.Controls.Add(Caption("Website address"));
            site.Controls.Add(txtSite);
            site.Controls.Add(Caption("API key"));
            var keyRow = Row();
            keyRow.Controls.AddRange(new Control[] { txtKey, chkShow });
            site.Controls.Add(keyRow);
            site.Controls.Add(Hint("Make a key in the site's admin panel under API keys (it's shown once). It's saved encrypted for your Windows account."));
            if (fallback[0] != null || fallback[2] != null)
                site.Controls.Add(Hint("Left blank, these come from " + string.Join(" and ", new[] { fallback[1], fallback[3] }.Where(x => x != null).Distinct()) + ", as the Node tools use."));
            var testRow = Row();
            testRow.Controls.AddRange(new Control[] { btnTest, lblTest });
            site.Controls.Add(testRow);
            root.Controls.Add(site.Parent);

            // ---- uploads and replays
            var up = Group("Uploads");
            up.Controls.AddRange(new Control[] { chkAfterRace, chkNewFiles });
            root.Controls.Add(up.Parent);
            var rep = Group("Replays");
            rep.Controls.Add(chkAutoHarvest);
            rep.Controls.Add(Hint("Launch the replay from the iRacing app (Replays). Any other replay can be harvested with \"Harvest this replay\"."));
            root.Controls.Add(rep.Parent);

            // ---- the logger
            var lg = Group("Logger");
            var hzRow = Row();
            hzRow.Controls.AddRange(new Control[] { new Label { Text = "Telemetry reads:", AutoSize = true, Margin = new Padding(0, 7, 6, 0) }, cmbHz });
            lg.Controls.AddRange(new Control[] { hzRow, chkStartup });
            lg.Controls.Add(Hint("Files are saved in " + uploader.Paths.Data));
            root.Controls.Add(lg.Parent);

            var buttons = new FlowLayoutPanel { FlowDirection = FlowDirection.RightToLeft, AutoSize = true, Width = 560, Margin = new Padding(0, 6, 0, 0) };
            var btnCancel = new Button { Text = "Cancel", DialogResult = DialogResult.Cancel, AutoSize = true };
            var btnSave = new Button { Text = "Save", AutoSize = true };
            buttons.Controls.AddRange(new Control[] { btnCancel, btnSave });
            root.Controls.Add(buttons);
            Controls.Add(root);
            AcceptButton = btnSave; CancelButton = btnCancel;

            // ---- values
            txtSite.Text = settings.Site;
            txtKey.Text = settings.ApiKey;
            txtSite.PlaceholderText = fallback[0] ?? "https://your-pit-wall-site";
            txtKey.PlaceholderText = fallback[2] != null ? "(from " + fallback[3] + ")" : "pw_…";
            chkAfterRace.Checked = settings.UploadAfterRace;
            chkNewFiles.Checked = settings.UploadNewFiles;
            chkAutoHarvest.Checked = settings.AutoHarvest;
            foreach (var hz in HzChoices) cmbHz.Items.Add(hz == 60 ? "Every tick (60/s)" : hz + " a second");
            cmbHz.SelectedIndex = Math.Max(0, Array.IndexOf(HzChoices, settings.Hz));
            try { chkStartup.Checked = Settings.StartsWithWindows; } catch { chkStartup.Enabled = false; }

            chkShow.CheckedChanged += (s, e) => txtKey.UseSystemPasswordChar = !chkShow.Checked;
            btnTest.Click += (s, e) => Test();
            btnSave.Click += (s, e) => Save();
        }

        static FlowLayoutPanel Group(string title)
        {
            var g = new GroupBox { Text = title, AutoSize = true, Width = 560, MinimumSize = new Size(560, 0), Padding = new Padding(10, 6, 10, 8), Margin = new Padding(0, 0, 0, 8) };
            var f = new FlowLayoutPanel { Dock = DockStyle.Fill, FlowDirection = FlowDirection.TopDown, WrapContents = false, AutoSize = true };
            g.Controls.Add(f);
            return f;
        }
        static FlowLayoutPanel Row() { return new FlowLayoutPanel { AutoSize = true, WrapContents = false, Margin = new Padding(0) }; }
        static Label Caption(string text) { return new Label { Text = text, AutoSize = true, Margin = new Padding(0, 6, 0, 2) }; }
        static Label Hint(string text) { return new Label { Text = text, AutoSize = true, ForeColor = Grey, MaximumSize = new Size(530, 0), Margin = new Padding(0, 4, 0, 2) }; }

        string SiteValue { get { return txtSite.Text.Trim().TrimEnd('/'); } }
        string KeyValue { get { return txtKey.Text.Trim(); } }

        void Test()
        {
            string site = SiteValue.Length > 0 ? SiteValue : fallback[0], key = KeyValue.Length > 0 ? KeyValue : fallback[2];
            btnTest.Enabled = false;
            lblTest.ForeColor = Grey; lblTest.Text = "Checking…";
            Task.Run(() =>
            {
                string text; bool ok;
                try
                {
                    var st = uploader.Status(site ?? "", key ?? "");
                    ok = true;
                    text = "Connected: " + st.Seasons + " season" + (st.Seasons == 1 ? "" : "s") + ", " + st.Races + " race" + (st.Races == 1 ? "" : "s")
                        + (st.Pending.Count > 0 ? ", " + st.Pending.Count + " replay" + (st.Pending.Count == 1 ? "" : "s") + " to harvest" : "");
                }
                catch (Exception e) { ok = false; text = (e.InnerException ?? e).Message; }
                try
                {
                    BeginInvoke(new Action(() =>
                    {
                        btnTest.Enabled = true;
                        lblTest.ForeColor = ok ? Green : Red;
                        lblTest.MaximumSize = new Size(300, 0);
                        lblTest.Text = (ok ? "✓ " : "✗ ") + text;
                    }));
                }
                catch (InvalidOperationException) { }    // closed meanwhile
            });
        }

        void Save()
        {
            if (SiteValue.Length > 0 && !SiteValue.StartsWith("http://", StringComparison.OrdinalIgnoreCase) && !SiteValue.StartsWith("https://", StringComparison.OrdinalIgnoreCase))
            {
                MessageBox.Show(this, "The website address should start with https://", Text, MessageBoxButtons.OK, MessageBoxIcon.Warning);
                txtSite.Focus();
                return;
            }
            settings.Site = SiteValue;
            settings.ApiKey = KeyValue;
            settings.UploadAfterRace = chkAfterRace.Checked;
            settings.UploadNewFiles = chkNewFiles.Checked;
            settings.AutoHarvest = chkAutoHarvest.Checked;
            settings.Hz = HzChoices[Math.Max(0, cmbHz.SelectedIndex)];
            settings.Save();
            if (chkStartup.Enabled)
            {
                try { if (Settings.StartsWithWindows != chkStartup.Checked) Settings.StartsWithWindows = chkStartup.Checked; }
                catch (Exception e) { MessageBox.Show(this, "Couldn't change the startup entry: " + e.Message, Text, MessageBoxButtons.OK, MessageBoxIcon.Warning); }
            }
            DialogResult = DialogResult.OK;
            Close();
        }
    }
}
