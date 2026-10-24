// Pit Wall Logger: logs every driver's incidents during a live iRacing session (all of them when
// you're a session admin, or outside a race) and uploads them, with new race files, to the site.
//
//   PitWallLogger.exe               open the window (one copy runs; a second launch shows the first)
//   PitWallLogger.exe --minimized   start in the tray (used by "Start with Windows")
//   PitWallLogger.exe --headless --out <dir> [--map <name> --event <name>]
//                                   no window: log one sim connection to <dir>, then exit (for testing)
using System;
using System.IO;
using System.Linq;
using System.Threading;
using System.Windows.Forms;

namespace PitWall
{
    static class Program
    {
        [STAThread]
        static int Main(string[] args)
        {
            Func<string, string> opt = f => { int i = Array.IndexOf(args, f); return i >= 0 && i + 1 < args.Length ? args[i + 1] : null; };
            var settings = Settings.Load();
            string root = settings.FindRoot();
            var engine = new Engine
            {
                OutDir = opt("--out") ?? (root != null ? Path.Combine(root, "data", "incidents", "live")
                    : Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments), "PitWall", "live")),
                Hz = settings.Hz,
            };
            if (opt("--map") != null) engine.MapName = opt("--map");
            if (opt("--event") != null) engine.EventName = opt("--event");

            if (args.Contains("--headless"))
            {
                Directory.CreateDirectory(engine.OutDir);
                string log = Path.Combine(engine.OutDir, "logger.log");
                engine.ExitWhenSimCloses = true;
                engine.OnLog += s => File.AppendAllText(log, DateTime.Now.ToString("HH:mm:ss.fff") + "  " + s + Environment.NewLine);
                engine.OnIncident += e => File.AppendAllText(log, string.Format("INCIDENT t={0:0.00} car={1} {2} {3}->{4} {5} lap={6} pct={7:0.0000} off={8}{9}",
                    e.T, e.Car, e.Name, e.From, e.To, e.Kind, e.Where == null ? -1 : e.Where.Lap, e.Where == null ? -1 : e.Where.Pct, e.Off != null, Environment.NewLine));
                engine.OnSaved += (f, race) => File.AppendAllText(log, "SAVED " + f + (race ? " (race finished)" : "") + Environment.NewLine);
                engine.Start();
                engine.Join();
                var st = engine.State;
                File.AppendAllText(log, string.Format("STATE view={0} text={1} incidents={2} tickHz={3:0.0} infoGap={4:0.00}{5}", st.View, st.ViewText, st.Incidents, st.TickHz, st.InfoGapS, Environment.NewLine));
                return 0;
            }

            bool first;
            using (var mutex = new Mutex(true, @"Local\PitWallLogger", out first))
            using (var show = new EventWaitHandle(false, EventResetMode.AutoReset, @"Local\PitWallLoggerShow"))
            {
                if (!first) { show.Set(); return 0; }   // already running: bring that one up
                Application.EnableVisualStyles();
                Application.SetCompatibleTextRenderingDefault(false);
                Application.SetHighDpiMode(HighDpiMode.PerMonitorV2);
                var form = new MainForm(engine, settings, root != null ? new Uploader(root) : null, args.Contains("--minimized"));
                string shot = opt("--screenshot");     // draw the window off-screen to a PNG after a few seconds, then quit (for testing)
                if (shot != null)
                {
                    form.NoActivate = true; form.ShowInTaskbar = false;
                    form.StartPosition = FormStartPosition.Manual; form.Location = new System.Drawing.Point(-20000, -20000);
                    var t = new System.Windows.Forms.Timer { Interval = int.Parse(opt("--after") ?? "4000") };
                    t.Tick += (s, e) =>
                    {
                        t.Stop();
                        using (var bmp = new System.Drawing.Bitmap(form.Width, form.Height))
                        {
                            form.DrawToBitmap(bmp, new System.Drawing.Rectangle(0, 0, form.Width, form.Height));
                            bmp.Save(shot, System.Drawing.Imaging.ImageFormat.Png);
                        }
                        form.Quit();
                    };
                    t.Start();
                }
                var listener = new Thread(() => { while (show.WaitOne()) { try { form.BeginInvoke(new Action(form.ShowWindow)); } catch { return; } } }) { IsBackground = true };
                listener.Start();
                engine.Start();
                Application.Run(form);
                engine.Stop();
            }
            return 0;
        }
    }
}
