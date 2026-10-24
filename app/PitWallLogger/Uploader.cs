// Uploads to the hosted Pit Wall site, the same way tools/push.ts does (and sharing its record of
// what was sent, .cache/pushed.json, so the two never resend each other's files):
//   POST {site}/api/v1/{results|incidents|schedule}?compile=0&filename=…[&season=…]   gzip JSON
//   POST {site}/api/v1/compile                                                       once, if anything changed
// Live logs go to /api/v1/live, which the site doesn't have yet: a 404 is reported, not an error.
using System;
using System.Collections.Generic;
using System.IO;
using System.IO.Compression;
using System.Linq;
using System.Net;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;
using System.Text.RegularExpressions;
using System.Text.Json;

namespace PitWall
{
    sealed class Uploader
    {
        public readonly string Root, DataDir, StateFile;
        public string Site { get; private set; }
        string key;
        static readonly object fileLock = new object();

        public Uploader(string root)
        {
            Root = root;
            DataDir = Path.Combine(root, "data");
            StateFile = Path.Combine(root, ".cache", "pushed.json");
            Reload();
        }

        /// <summary>Site address and API key: environment, then .env, then pitwall.config.json / .pitwall-key (as tools/lib/config.ts).</summary>
        public void Reload()
        {
            var env = new Dictionary<string, string>();
            string envFile = Path.Combine(Root, ".env");
            if (File.Exists(envFile))
                foreach (var line in File.ReadAllLines(envFile))
                {
                    var m = Regex.Match(line, @"^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$");
                    if (m.Success) env[m.Groups[1].Value] = m.Groups[2].Value.Trim('"', '\'');
                }
            Func<string, string> get = k => { var v = Environment.GetEnvironmentVariable(k); if (!string.IsNullOrWhiteSpace(v)) return v.Trim(); string f; return env.TryGetValue(k, out f) && f.Length > 0 ? f : null; };
            string site = get("PITWALL_SITE");
            if (site == null)
            {
                try
                {
                    using (var cfg = JsonDocument.Parse(File.ReadAllText(Path.Combine(Root, "pitwall.config.json"))))
                    {
                        JsonElement s;
                        if (cfg.RootElement.TryGetProperty("site", out s) && s.ValueKind == JsonValueKind.String) site = s.GetString();
                    }
                }
                catch { }
            }
            Site = string.IsNullOrWhiteSpace(site) ? null : site.TrimEnd('/');
            key = get("PITWALL_API_KEY");
            string keyFile = Path.Combine(Root, ".pitwall-key");
            if (key == null && File.Exists(keyFile)) key = (File.ReadAllLines(keyFile).FirstOrDefault() ?? "").Trim();
            if (key == "") key = null;
        }

        public bool Configured { get { return Site != null && key != null; } }

        static string KindOf(string f)
        {
            string n = Path.GetFileName(f);
            if (Regex.IsMatch(n, @"^eventresult-.*\.json$", RegexOptions.IgnoreCase)) return "results";
            if (Regex.IsMatch(n, @"^incidents-\d+\.json$", RegexOptions.IgnoreCase)) return "incidents";
            if (Regex.IsMatch(n, @"^schedule.*\.json$", RegexOptions.IgnoreCase)) return "schedule";
            return null;
        }

        /// <summary>The season a file belongs to: its first folder under data/ (not for harvests).</summary>
        string SeasonOf(string f)
        {
            string rel = MakeRelative(DataDir, Path.GetFullPath(f));
            if (rel == null) return null;
            var parts = rel.Split(Path.DirectorySeparatorChar);
            return parts.Length > 1 && parts[0] != "incidents" ? parts[0] : null;
        }
        static string MakeRelative(string dir, string path)
        {
            string d = Path.GetFullPath(dir).TrimEnd(Path.DirectorySeparatorChar) + Path.DirectorySeparatorChar;
            return path.StartsWith(d, StringComparison.OrdinalIgnoreCase) ? path.Substring(d.Length) : null;
        }

        /// <summary>Every result, harvest and schedule file under data/ (as tools/push.ts allDataFiles).</summary>
        public List<string> AllDataFiles()
        {
            var files = new List<string>();
            if (!Directory.Exists(DataDir)) return files;
            foreach (var dir in Directory.GetDirectories(DataDir).OrderBy(d => d, StringComparer.Ordinal))
            {
                string name = Path.GetFileName(dir);
                if (name.StartsWith(".") || name == "incidents") continue;
                files.AddRange(Directory.GetFiles(dir, "eventresult-*.json", SearchOption.AllDirectories).OrderBy(f => f, StringComparer.Ordinal));
            }
            string inc = Path.Combine(DataDir, "incidents");
            if (Directory.Exists(inc)) files.AddRange(Directory.GetFiles(inc, "incidents-*.json").Where(f => KindOf(f) == "incidents"));
            foreach (var dir in Directory.GetDirectories(DataDir))
            {
                string s = Path.Combine(dir, "schedule.json");
                if (Path.GetFileName(dir) != "incidents" && File.Exists(s)) files.Add(s);
            }
            return files;
        }

        Dictionary<string, string> ReadState()
        {
            try { return JsonSerializer.Deserialize<Dictionary<string, string>>(File.ReadAllText(StateFile)) ?? new Dictionary<string, string>(); }
            catch { return new Dictionary<string, string>(); }
        }

        string Mark(byte[] body, string season)
        {
            using (var sha = SHA1.Create())
                return BitConverter.ToString(sha.ComputeHash(body)).Replace("-", "").ToLowerInvariant() + "@" + Site + "#" + (season ?? "");
        }

        /// <summary>Files under data/ that differ from what was last sent to this site.</summary>
        public List<string> Pending()
        {
            if (Site == null) return new List<string>();
            var state = ReadState();
            return AllDataFiles().Where(f =>
            {
                try { string m; return !(state.TryGetValue(Path.GetFullPath(f), out m) && m == Mark(File.ReadAllBytes(f), KindOf(f) == "incidents" ? null : SeasonOf(f))); }
                catch { return false; }
            }).ToList();
        }

        HttpClient Client()
        {
            ServicePointManager.SecurityProtocol |= SecurityProtocolType.Tls12;
            var c = new HttpClient { Timeout = TimeSpan.FromSeconds(120) };
            c.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue("Bearer", key);
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

        /// <summary>Send these files (skipping ones already sent unchanged), then republish once. Returns (sent, failed).</summary>
        public int[] Push(IEnumerable<string> files, Action<string> log, bool force = false)
        {
            if (!Configured) { log("Not uploaded: no site address or API key (set PITWALL_SITE and PITWALL_API_KEY in .env)."); return new[] { 0, 0 }; }
            lock (fileLock)
            {
                var state = ReadState();
                int sent = 0, failed = 0;
                using (var c = Client())
                {
                    foreach (var f in files)
                    {
                        string kind = KindOf(f), name = Path.GetFileName(f);
                        if (kind == null) { log("skip " + name); continue; }
                        try
                        {
                            byte[] body = File.ReadAllBytes(f);
                            string season = kind == "incidents" ? null : SeasonOf(f);
                            string mark = Mark(body, season), id = Path.GetFullPath(f);
                            string prev;
                            if (!force && state.TryGetValue(id, out prev) && prev == mark) continue;
                            string q = "?compile=0&filename=" + Uri.EscapeDataString(name) + (season != null ? "&season=" + Uri.EscapeDataString(season) : "");
                            string text;
                            var res = Post(c, "/" + kind + q, body, out text);
                            if (!res.IsSuccessStatusCode) { log("✗ " + name + ": " + ErrorOf(res, text)); failed++; continue; }
                            string status = "sent";
                            try
                            {
                                using (var j = JsonDocument.Parse(text))
                                {
                                    JsonElement st, v;
                                    if (j.RootElement.TryGetProperty("stored", out st) && st.TryGetProperty("status", out v)) status = v.ToString();
                                }
                            }
                            catch { }
                            log(status + "  " + name + (season != null ? " → " + season : ""));
                            state[id] = mark;
                            if (status != "unchanged") sent++;
                        }
                        catch (Exception e) { log("✗ " + name + ": " + (e.InnerException ?? e).Message); failed++; }
                    }
                    Directory.CreateDirectory(Path.GetDirectoryName(StateFile));
                    string tmp = StateFile + ".tmp";
                    File.WriteAllText(tmp, JsonSerializer.Serialize(state));
                    if (File.Exists(StateFile)) File.Delete(StateFile);
                    File.Move(tmp, StateFile);
                    if (sent > 0)
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

        /// <summary>Send a live log. The site doesn't accept these yet; a 404 says so.</summary>
        public bool PushLive(string file, Action<string> log)
        {
            if (!Configured) { log("Live log not uploaded: no site address or API key."); return false; }
            try
            {
                using (var c = Client())
                {
                    string text;
                    var res = Post(c, "/live?filename=" + Uri.EscapeDataString(Path.GetFileName(file)), File.ReadAllBytes(file), out text);
                    if (res.StatusCode == HttpStatusCode.NotFound) { log("Live log kept on this PC: the site doesn't accept live logs yet."); return false; }
                    if (!res.IsSuccessStatusCode) { log("✗ live log " + Path.GetFileName(file) + ": " + ErrorOf(res, text)); return false; }
                    log("Uploaded live log " + Path.GetFileName(file) + ".");
                    return true;
                }
            }
            catch (Exception e) { log("✗ live log: " + (e.InnerException ?? e).Message); return false; }
        }
    }
}
