// Uploads to the hosted Pit Wall site, the same way tools/push.ts does (and sharing its record of
// what was sent, .cache/pushed.json, so the two never resend each other's files):
//   POST {site}/api/v1/{results|incidents|schedule|live}?compile=0&filename=…[&season=…]   gzip JSON
//   POST {site}/api/v1/compile                                                            once, if a race file changed
//   GET  {site}/api/v1/status                                                             seasons, and replays still to harvest
// The site address and API key come from Settings; when those are blank, from PITWALL_SITE and
// PITWALL_API_KEY (environment, or .env in the Pit Wall folder) as the Node tools read them.
using System;
using System.Collections.Generic;
using System.IO;
using System.IO.Compression;
using System.Linq;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace PitWall
{
    sealed class PendingHarvest { public string Season = "", Track = ""; public int Round; public long Sub; }
    sealed class SiteStatus { public int Seasons, Races; public readonly List<PendingHarvest> Pending = new List<PendingHarvest>(); }

    sealed class Uploader
    {
        readonly Settings settings;
        public readonly Paths Paths;
        readonly string stateFile;
        public string Site { get; private set; }
        string key;
        static readonly object fileLock = new object();

        public Uploader(Settings settings, Paths paths)
        {
            this.settings = settings;
            Paths = paths;
            stateFile = Path.Combine(paths.Cache, "pushed.json");
            Reload();
        }

        /// <summary>Re-read the site address and API key (after Settings change, or .env was edited).</summary>
        public void Reload()
        {
            var fb = Fallback(Paths.Root);
            string site = settings.Site.Trim().Length > 0 ? settings.Site.Trim() : fb[0];
            Site = string.IsNullOrWhiteSpace(site) ? null : site.TrimEnd('/');
            key = settings.ApiKey.Trim().Length > 0 ? settings.ApiKey.Trim() : fb[2];
        }

        public bool Configured { get { return Site != null && key != null; } }

        /// <summary>The address and key the Node tools would use: { site, where from, key, where from } (nulls when there are none).
        /// Environment first, then .env in the Pit Wall folder; the address can also be "site" in pitwall.config.json, the key .pitwall-key.</summary>
        public static string[] Fallback(string root)
        {
            var env = new Dictionary<string, string>();
            string envFile = root == null ? null : Path.Combine(root, ".env");
            if (envFile != null && File.Exists(envFile))
                foreach (var line in File.ReadAllLines(envFile))
                {
                    var m = Regex.Match(line, @"^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$");
                    if (m.Success) env[m.Groups[1].Value] = m.Groups[2].Value.Trim('"', '\'');
                }
            string[] r = new string[4];
            Action<string, int> get = (k, i) =>
            {
                var v = Environment.GetEnvironmentVariable(k);
                string f;
                if (!string.IsNullOrWhiteSpace(v)) { r[i] = v.Trim(); r[i + 1] = "the " + k + " environment variable"; }
                else if (env.TryGetValue(k, out f) && f.Length > 0) { r[i] = f; r[i + 1] = k + " in .env"; }
            };
            get("PITWALL_SITE", 0);
            get("PITWALL_API_KEY", 2);
            if (r[0] == null && root != null)
            {
                try
                {
                    using (var cfg = JsonDocument.Parse(File.ReadAllText(Path.Combine(root, "pitwall.config.json"))))
                    {
                        JsonElement s;
                        if (cfg.RootElement.TryGetProperty("site", out s) && s.ValueKind == JsonValueKind.String && s.GetString().Trim().Length > 0) { r[0] = s.GetString().Trim(); r[1] = "pitwall.config.json"; }
                    }
                }
                catch { }
            }
            string keyFile = root == null ? null : Path.Combine(root, ".pitwall-key");
            if (r[2] == null && keyFile != null && File.Exists(keyFile))
            {
                string k = (File.ReadAllLines(keyFile).FirstOrDefault() ?? "").Trim();
                if (k.Length > 0) { r[2] = k; r[3] = ".pitwall-key"; }
            }
            return r;
        }

        static string KindOf(string f)
        {
            string n = Path.GetFileName(f);
            if (Regex.IsMatch(n, @"^eventresult-.*\.json$", RegexOptions.IgnoreCase)) return "results";
            if (Regex.IsMatch(n, @"^incidents-\d+\.json$", RegexOptions.IgnoreCase)) return "incidents";
            if (Regex.IsMatch(n, @"^schedule.*\.json$", RegexOptions.IgnoreCase)) return "schedule";
            if (Regex.IsMatch(n, @"^live-.*\.json$", RegexOptions.IgnoreCase)) return "live";
            return null;
        }

        public static bool IsLive(string f) { return KindOf(f) == "live"; }

        static bool NotSeason(string folder) { return folder.StartsWith(".") || folder == "incidents" || folder == "live"; }

        /// <summary>The season a race result or schedule belongs to: its first folder under data/.</summary>
        string SeasonOf(string f)
        {
            string kind = KindOf(f);
            if (kind != "results" && kind != "schedule") return null;
            string d = Path.GetFullPath(Paths.Data).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar, p = Path.GetFullPath(f);
            if (!p.StartsWith(d, StringComparison.OrdinalIgnoreCase)) return null;
            var parts = p.Substring(d.Length).Split(Path.DirectorySeparatorChar);
            return parts.Length > 1 && !NotSeason(parts[0]) ? parts[0] : null;
        }

        /// <summary>Every result, harvest, schedule and live log (as tools/push.ts allDataFiles).</summary>
        public List<string> AllDataFiles()
        {
            var files = new List<string>();
            if (Directory.Exists(Paths.Data))
                foreach (var dir in Directory.GetDirectories(Paths.Data).OrderBy(d => d, StringComparer.Ordinal))
                    if (!NotSeason(Path.GetFileName(dir)))
                        files.AddRange(Directory.GetFiles(dir, "eventresult-*.json", SearchOption.AllDirectories).OrderBy(f => f, StringComparer.Ordinal));
            if (Directory.Exists(Paths.Incidents)) files.AddRange(Directory.GetFiles(Paths.Incidents, "incidents-*.json").Where(f => KindOf(f) == "incidents").OrderBy(f => f, StringComparer.Ordinal));
            if (Directory.Exists(Paths.Data))
                foreach (var dir in Directory.GetDirectories(Paths.Data))
                {
                    string s = Path.Combine(dir, "schedule.json");
                    if (!NotSeason(Path.GetFileName(dir)) && File.Exists(s)) files.Add(s);
                }
            if (Directory.Exists(Paths.Live)) files.AddRange(Directory.GetFiles(Paths.Live, "live-*.json").OrderBy(f => f, StringComparer.Ordinal));
            return files;
        }

        Dictionary<string, string> ReadState()
        {
            try { return JsonSerializer.Deserialize<Dictionary<string, string>>(File.ReadAllText(stateFile)) ?? new Dictionary<string, string>(); }
            catch { return new Dictionary<string, string>(); }
        }

        string Mark(byte[] body, string season)
        {
            using (var sha = SHA1.Create())
                return BitConverter.ToString(sha.ComputeHash(body)).Replace("-", "").ToLowerInvariant() + "@" + Site + "#" + (season ?? "");
        }

        /// <summary>Files that differ from what was last sent to this site.</summary>
        public List<string> Pending()
        {
            if (Site == null) return new List<string>();
            var state = ReadState();
            return AllDataFiles().Where(f =>
            {
                try { string m; return !(state.TryGetValue(Path.GetFullPath(f), out m) && m == Mark(File.ReadAllBytes(f), SeasonOf(f))); }
                catch { return false; }
            }).ToList();
        }

        static HttpClient Client(string apiKey)
        {
            var c = new HttpClient { Timeout = TimeSpan.FromSeconds(120) };
            c.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", apiKey);
            c.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
            return c;
        }

        static byte[] Gzip(byte[] data)
        {
            using (var ms = new MemoryStream())
            {
                using (var gz = new GZipStream(ms, CompressionLevel.Optimal)) gz.Write(data, 0, data.Length);
                return ms.ToArray();
            }
        }

        static string ErrorOf(HttpResponseMessage res, string text)
        {
            try
            {
                using (var j = JsonDocument.Parse(text))
                {
                    JsonElement e, m;
                    if (j.RootElement.TryGetProperty("error", out e) && e.ValueKind == JsonValueKind.Object && e.TryGetProperty("message", out m)) return m.GetString();
                }
            }
            catch { }
            return (int)res.StatusCode + " " + res.ReasonPhrase + (text.TrimStart().StartsWith("<") ? " (the server answered with a web page: check the site address)" : "");
        }

        HttpResponseMessage Post(HttpClient c, string path, byte[] body, out string text)
        {
            var content = body == null ? new ByteArrayContent(new byte[0]) : new ByteArrayContent(Gzip(body));
            if (body != null) { content.Headers.ContentType = new MediaTypeHeaderValue("application/json"); content.Headers.ContentEncoding.Add("gzip"); }
            var res = c.PostAsync(Site + "/api/v1" + path, content).Result;
            text = res.Content.ReadAsStringAsync().Result;
            return res;
        }

        static string Str(JsonElement o, string name)
        {
            JsonElement v;
            return o.ValueKind == JsonValueKind.Object && o.TryGetProperty(name, out v) ? v.ToString() : "";
        }

        /// <summary>Send these files (skipping ones already sent unchanged), then republish once if a race file changed. Returns (sent, failed).</summary>
        public int[] Push(IEnumerable<string> files, Action<string> log, bool force = false)
        {
            if (!Configured) { log("Not uploaded: no site address or API key (set them in Settings)."); return new[] { 0, 0 }; }
            lock (fileLock)
            {
                var state = ReadState();
                int sent = 0, failed = 0;
                bool publish = false;
                using (var c = Client(key))
                {
                    foreach (var f in files)
                    {
                        string kind = KindOf(f), name = Path.GetFileName(f);
                        if (kind == null) { log("skip " + name); continue; }
                        try
                        {
                            byte[] body = File.ReadAllBytes(f);
                            string season = SeasonOf(f);
                            string mark = Mark(body, season), id = Path.GetFullPath(f);
                            string prev;
                            if (!force && state.TryGetValue(id, out prev) && prev == mark) continue;
                            string q = "?compile=0&filename=" + Uri.EscapeDataString(name) + (season != null ? "&season=" + Uri.EscapeDataString(season) : "");
                            string text;
                            var res = Post(c, "/" + kind + q, body, out text);
                            if (!res.IsSuccessStatusCode) { log("✗ " + name + ": " + ErrorOf(res, text)); failed++; continue; }
                            string status = "sent", what = season != null ? " → " + season : "";
                            try
                            {
                                using (var j = JsonDocument.Parse(text))
                                {
                                    JsonElement st;
                                    if (j.RootElement.TryGetProperty("stored", out st))
                                    {
                                        status = Str(st, "status");
                                        if (kind == "live") what = " · " + Str(st, "incidents") + " incidents → " + Site + "/live/" + Str(st, "id");
                                        else if (kind == "incidents") what = " · " + Str(st, "incidents") + " incidents" + (Str(st, "matched") == "False" || Str(st, "matched") == "false" ? " (race not uploaded yet)" : "");
                                    }
                                }
                            }
                            catch { }
                            log(status + "  " + name + what);
                            state[id] = mark;
                            if (status != "unchanged") { sent++; publish |= kind != "live"; }   // a live log stands alone: nothing to recompile
                        }
                        catch (Exception e) { log("✗ " + name + ": " + (e.InnerException ?? e).Message); failed++; }
                    }
                    Directory.CreateDirectory(Path.GetDirectoryName(stateFile));
                    string tmp = stateFile + ".tmp";
                    File.WriteAllText(tmp, JsonSerializer.Serialize(state));
                    File.Move(tmp, stateFile, true);
                    if (publish)
                    {
                        try
                        {
                            string text;
                            var res = Post(c, "/compile", null, out text);
                            log(res.IsSuccessStatusCode ? "Site republished." : "Republish failed: " + ErrorOf(res, text));
                        }
                        catch (Exception e) { log("Republish failed: " + (e.InnerException ?? e).Message); }
                    }
                }
                return new[] { sent, failed };
            }
        }

        /// <summary>What the site holds and which replays it still needs, with the configured address and key,
        /// or the ones given (to test them before saving). Throws with a readable message when it can't.</summary>
        public SiteStatus Status(string site = null, string apiKey = null)
        {
            site = (site ?? Site ?? "").Trim().TrimEnd('/');
            apiKey = (apiKey ?? key ?? "").Trim();
            if (site.Length == 0) throw new Exception("No site address.");
            if (!Regex.IsMatch(site, @"^https?://[^/\s]+", RegexOptions.IgnoreCase)) throw new Exception("The site address should start with https://");
            if (apiKey.Length == 0) throw new Exception("No API key. Make one in the site's admin panel under API keys.");
            using (var c = Client(apiKey))
            {
                HttpResponseMessage res;
                try { res = c.GetAsync(site + "/api/v1/status").Result; }
                catch (Exception e) { throw new Exception("Couldn't reach " + site + ": " + (e.InnerException ?? e).Message); }
                string text = res.Content.ReadAsStringAsync().Result;
                if (!res.IsSuccessStatusCode) throw new Exception(ErrorOf(res, text));
                var st = new SiteStatus();
                try
                {
                    using (var j = JsonDocument.Parse(text))
                    {
                        foreach (var s in j.RootElement.GetProperty("seasons").EnumerateArray()) { st.Seasons++; st.Races += s.GetProperty("races").GetArrayLength(); }
                        foreach (var p in j.RootElement.GetProperty("pending_harvests").EnumerateArray())
                            st.Pending.Add(new PendingHarvest { Season = Str(p, "season"), Track = Str(p, "track"), Round = p.GetProperty("round").GetInt32(), Sub = p.GetProperty("subsession").GetInt64() });
                    }
                }
                catch (Exception) { throw new Exception("The site answered, but not like Pit Wall does: check the address."); }
                return st;
            }
        }
    }
}
