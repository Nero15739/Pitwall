// Settings in %APPDATA%\PitWall\logger.ini (key=value), and the "start with Windows" entry.
using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using Microsoft.Win32;

namespace PitWall
{
    sealed class Settings
    {
        public bool UploadAfterRace = true;     // send the live log (and anything waiting in data/) when a race finishes
        public bool UploadNewFiles = false;     // send new files in data/ as they land (the old watcher's job)
        public int Hz = 60;                     // telemetry reads per second while in a session (60 = every tick)
        public string Root = "";                // the Pit Wall folder (holds data/ and .env); blank = find it

        static string FilePath { get { return Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "PitWall", "logger.ini"); } }

        public static Settings Load()
        {
            var s = new Settings();
            try
            {
                if (!File.Exists(FilePath)) return s;
                var kv = File.ReadAllLines(FilePath).Select(l => l.Split(new[] { '=' }, 2)).Where(p => p.Length == 2).ToDictionary(p => p[0].Trim(), p => p[1].Trim());
                string v;
                if (kv.TryGetValue("UploadAfterRace", out v)) s.UploadAfterRace = v == "1";
                if (kv.TryGetValue("UploadNewFiles", out v)) s.UploadNewFiles = v == "1";
                int hz; if (kv.TryGetValue("Hz", out v) && int.TryParse(v, out hz) && hz >= 1 && hz <= 60) s.Hz = hz;
                if (kv.TryGetValue("Root", out v)) s.Root = v;
            }
            catch { }
            return s;
        }

        public void Save()
        {
            try
            {
                Directory.CreateDirectory(Path.GetDirectoryName(FilePath));
                File.WriteAllLines(FilePath, new[] {
                    "UploadAfterRace=" + (UploadAfterRace ? 1 : 0), "UploadNewFiles=" + (UploadNewFiles ? 1 : 0), "Hz=" + Hz, "Root=" + Root });
            }
            catch { }
        }

        /// <summary>The Pit Wall folder: the setting, else the first folder up from the exe that has pitwall.config.json.</summary>
        public string FindRoot()
        {
            if (Root.Length > 0 && Directory.Exists(Root)) return Root;
            for (var d = new DirectoryInfo(AppDomain.CurrentDomain.BaseDirectory); d != null; d = d.Parent)
                if (File.Exists(Path.Combine(d.FullName, "pitwall.config.json"))) return d.FullName;
            return null;
        }

        const string RunKey = @"Software\Microsoft\Windows\CurrentVersion\Run", RunName = "PitWallLogger";
        public static bool StartsWithWindows
        {
            get { using (var k = Registry.CurrentUser.OpenSubKey(RunKey)) return k != null && k.GetValue(RunName) != null; }
            set
            {
                using (var k = Registry.CurrentUser.CreateSubKey(RunKey))
                {
                    if (value) k.SetValue(RunName, "\"" + System.Windows.Forms.Application.ExecutablePath + "\" --minimized");
                    else k.DeleteValue(RunName, false);
                }
            }
        }
    }
}
