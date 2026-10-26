// Pit Wall Logger: logs every driver's incidents during a live iRacing session (all of them when
// you're a session admin, or outside a race), harvests race replays (crash spots, sector times, lap
// chart), and uploads both, with new race files, to the site.
//
//   PitWallLogger.exe               open the window (one copy runs; a second launch shows the first)
//   PitWallLogger.exe --minimized   start in the tray (used by "Start with Windows")
//
// For testing (never against the real sim's names):
//   --headless --out <dir> [--map <name> --event <name>] [--harvest --msg <name>]
//                                   no window: log one sim connection to <dir>, then exit; with --harvest,
//                                   harvest the race replay that's open into <dir> instead, steering it with
//                                   broadcast message <name> (IRSDK_BROADCASTMSG for the real sim), then exit
//   --settings <file>               read (and save) settings there instead of %APPDATA%\PitWall\logger.ini
//   --screenshot <png> [--after <ms>]  draw the window to a PNG and quit (add --dialog for the Settings window)
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
            if (opt("--settings") != null) Settings.FilePath = Path.GetFullPath(opt("--settings"));
            var settings = Settings.Load();
            var paths = Paths.For(settings.FindRoot());
            var engine = new Engine { OutDir = opt("--out") ?? paths.Live, HarvestDir = opt("--out") ?? paths.Incidents, Hz = settings.Hz };
            if (opt("--map") != null) engine.MapName = opt("--map");
            if (opt("--event") != null) engine.EventName = opt("--event");
            if (opt("--msg") != null) engine.BroadcastName = opt("--msg");

            if (args.Contains("--headless"))
            {
                Directory.CreateDirectory(engine.OutDir);
                string log = Path.Combine(engine.OutDir, "logger.log");
                engine.ExitWhenSimCloses = true;
                engine.HarvestReplays = args.Contains("--harvest");
                if (engine.HarvestReplays && opt("--msg") == null) { Console.Error.WriteLine("--harvest needs --msg <a private message name>"); return 2; }
                Action<string> write = s => File.AppendAllText(log, DateTime.Now.ToString("HH:mm:ss.fff") + "  " + s + Environment.NewLine);
                engine.OnLog += write;
                engine.OnDetail += write;
                engine.OnIncident += e => File.AppendAllText(log, string.Format("INCIDENT t={0:0.00} car={1} {2} {3}->{4} {5} lap={6} pct={7:0.0000} off={8}{9}",
                    e.T, e.Car, e.Name, e.From, e.To, e.Kind, e.Where == null ? -1 : e.Where.Lap, e.Where == null ? -1 : e.Where.Pct, e.Off != null, Environment.NewLine));
                engine.OnSaved += (f, race) => File.AppendAllText(log, "SAVED " + f + (race ? " (race finished)" : "") + Environment.NewLine);
                engine.OnHarvested += f => File.AppendAllText(log, "HARVESTED " + f + Environment.NewLine);
                engine.Start();
                engine.Join();
                var st = engine.State;
                File.AppendAllText(log, string.Format("STATE view={0} text={1} incidents={2} tickHz={3:0.0} infoGap={4:0.00}{5}", st.View, st.ViewText, st.Incidents, st.TickHz, st.InfoGapS, Environment.NewLine));
                return 0;
            }

            string shot = opt("--screenshot");     // draw the window off-screen to a PNG after a few seconds, then quit (for testing)
            if (shot != null && opt("--msg") == null) engine.BroadcastName = "PitWallLoggerScreenshot";   // a test run never steers a real replay
            bool first;
            using (var mutex = new Mutex(true, shot != null ? @"Local\PitWallLoggerScreenshot" : @"Local\PitWallLogger", out first))
            using (var show = new EventWaitHandle(false, EventResetMode.AutoReset, shot != null ? @"Local\PitWallLoggerScreenshotShow" : @"Local\PitWallLoggerShow"))
            {
                if (!first) { show.Set(); return 0; }   // already running: bring that one up
                Application.EnableVisualStyles();
                Application.SetCompatibleTextRenderingDefault(false);
                Application.SetHighDpiMode(HighDpiMode.PerMonitorV2);
                var form = new MainForm(engine, settings, new Uploader(settings, paths), args.Contains("--minimized"));
                if (shot != null)
                {
                    form.NoActivate = true; form.ShowInTaskbar = false;
                    form.StartPosition = FormStartPosition.Manual; form.Location = new System.Drawing.Point(-20000, -20000);
                    var t = new System.Windows.Forms.Timer { Interval = int.Parse(opt("--after") ?? "4000") };
                    t.Tick += (s, e) =>
                    {
                        t.Stop();
                        Form target = form;
                        if (args.Contains("--dialog"))
                        {
                            target = new SettingsForm(settings, new Uploader(settings, paths)) { StartPosition = FormStartPosition.Manual, Location = new System.Drawing.Point(-20000, -20000), ShowInTaskbar = false };
                            target.Show();
                        }
                        using (var bmp = new System.Drawing.Bitmap(target.Width, target.Height))
                        {
                            target.DrawToBitmap(bmp, new System.Drawing.Rectangle(0, 0, target.Width, target.Height));
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
