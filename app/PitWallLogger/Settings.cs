// Settings in %APPDATA%\PitWall\logger.ini (key=value), where files go, and the "start with Windows" entry.
// The API key is stored encrypted for this Windows user (DPAPI), so the file alone doesn't give it away.
using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Runtime.InteropServices;
using System.Text;
using Microsoft.Win32;

namespace PitWall
{
    sealed class Settings
    {
        public string Site = "";                // the Pit Wall site, e.g. https://iracing.example.com (blank: PITWALL_SITE from .env)
        public string ApiKey = "";              // made in the site's admin panel under API keys (blank: PITWALL_API_KEY from .env)
        public bool UploadAfterRace = true;     // send the live log (and anything waiting in data/) when a race finishes
        public bool UploadNewFiles = false;     // send new files in data/ as they land (the old watcher's job)
        public bool AutoHarvest = true;         // harvest a league race's replay when it's opened, if the site still needs it
        public int Hz = 60;                     // telemetry reads per second while in a session (60 = every tick)
        public string Root = "";                // the Pit Wall folder (holds data/ and .env); blank = find it

        /// <summary>The settings file; --settings &lt;file&gt; points elsewhere (for testing).</summary>
        public static string FilePath = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "PitWall", "logger.ini");

        public static Settings Load()
        {
            var s = new Settings();
            try
            {
                if (!File.Exists(FilePath)) return s;
                var kv = new Dictionary<string, string>();
                foreach (var p in File.ReadAllLines(FilePath).Select(l => l.Split(new[] { '=' }, 2)).Where(p => p.Length == 2)) kv[p[0].Trim()] = p[1].Trim();
                string v;
                if (kv.TryGetValue("Site", out v)) s.Site = v;
                if (kv.TryGetValue("ApiKey", out v)) s.ApiKey = Unprotect(v);
                if (kv.TryGetValue("UploadAfterRace", out v)) s.UploadAfterRace = v == "1";
                if (kv.TryGetValue("UploadNewFiles", out v)) s.UploadNewFiles = v == "1";
                if (kv.TryGetValue("AutoHarvest", out v)) s.AutoHarvest = v == "1";
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
                    "Site=" + Site, "ApiKey=" + Protect(ApiKey),
                    "UploadAfterRace=" + (UploadAfterRace ? 1 : 0), "UploadNewFiles=" + (UploadNewFiles ? 1 : 0), "AutoHarvest=" + (AutoHarvest ? 1 : 0),
                    "Hz=" + Hz, "Root=" + Root });
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

        // ---------------------------------------------------------------- the API key at rest (DPAPI, this Windows user)
        [StructLayout(LayoutKind.Sequential)] struct Blob { public int Size; public IntPtr Data; }
        [DllImport("crypt32", CharSet = CharSet.Unicode, SetLastError = true)]
        static extern bool CryptProtectData(ref Blob input, string description, IntPtr entropy, IntPtr reserved, IntPtr prompt, int flags, out Blob output);
        [DllImport("crypt32", CharSet = CharSet.Unicode, SetLastError = true)]
        static extern bool CryptUnprotectData(ref Blob input, IntPtr description, IntPtr entropy, IntPtr reserved, IntPtr prompt, int flags, out Blob output);
        [DllImport("kernel32")] static extern IntPtr LocalFree(IntPtr p);
        const int UI_FORBIDDEN = 1;

        static byte[] Dpapi(byte[] data, bool protect)
        {
            var input = new Blob { Size = data.Length, Data = Marshal.AllocHGlobal(Math.Max(1, data.Length)) };
            try
            {
                Marshal.Copy(data, 0, input.Data, data.Length);
                Blob output;
                bool ok = protect ? CryptProtectData(ref input, "Pit Wall API key", IntPtr.Zero, IntPtr.Zero, IntPtr.Zero, UI_FORBIDDEN, out output)
                                  : CryptUnprotectData(ref input, IntPtr.Zero, IntPtr.Zero, IntPtr.Zero, IntPtr.Zero, UI_FORBIDDEN, out output);
                if (!ok) return null;
                var bytes = new byte[output.Size];
                Marshal.Copy(output.Data, bytes, 0, output.Size);
                LocalFree(output.Data);
                return bytes;
            }
            finally { Marshal.FreeHGlobal(input.Data); }
        }

        static string Protect(string key)
        {
            if (string.IsNullOrEmpty(key)) return "";
            var b = Dpapi(Encoding.UTF8.GetBytes(key), true);
            return b == null ? "" : "dpapi:" + Convert.ToBase64String(b);
        }

        /// <summary>A stored key ("dpapi:…", or plain text typed into the file by hand). Blank when it can't be read (another user or PC).</summary>
        static string Unprotect(string v)
        {
            if (!v.StartsWith("dpapi:")) return v;
            try { var b = Dpapi(Convert.FromBase64String(v.Substring(6)), false); return b == null ? "" : Encoding.UTF8.GetString(b); }
            catch { return ""; }
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

    /// <summary>Where files go. In the Pit Wall folder: data\incidents (harvests), data\incidents\live (live logs),
    /// .cache (what's been uploaded, shared with the Node tools). On a PC without one: Documents\PitWall.</summary>
    sealed class Paths
    {
        public string Root;                     // the Pit Wall folder, or null
        public string Data, Incidents, Live, Cache;

        public static Paths For(string root)
        {
            if (root != null)
            {
                string data = Path.Combine(root, "data"), inc = Path.Combine(data, "incidents");
                return new Paths { Root = root, Data = data, Incidents = inc, Live = Path.Combine(inc, "live"), Cache = Path.Combine(root, ".cache") };
            }
            string home = Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments), "PitWall");
            return new Paths { Data = home, Incidents = Path.Combine(home, "incidents"), Live = Path.Combine(home, "live"), Cache = home };
        }
    }
}
