// Just enough YAML for iRacing's session info: nested maps and lists of maps, one "key: value" per
// line, list items as "- key: value" at the parent key's indent. Values stay strings (free text
// such as "UserName: *Weird: Name" is kept whole after the first colon).
using System;
using System.Collections.Generic;
using System.Globalization;

namespace PitWall
{
    static class Yaml
    {
        sealed class Frame { public int Indent; public Dictionary<string, object> Map; public List<object> List; }

        public static Dictionary<string, object> Parse(string text)
        {
            var root = new Dictionary<string, object>();
            var frames = new List<Frame> { new Frame { Indent = 0, Map = root } };
            Dictionary<string, object> pendMap = null; string pendKey = null; int pendIndent = -1;

            foreach (var rawLine in text.Split('\n'))
            {
                string line = rawLine.TrimEnd('\r', ' ', '\t');
                int col = 0;
                while (col < line.Length && line[col] == ' ') col++;
                string s = line.Substring(col);
                if (s.Length == 0 || s.StartsWith("---") || s == "..." || s.StartsWith("#")) continue;
                bool dash = s == "-" || s.StartsWith("- ");

                if (pendKey != null)
                {
                    if (dash && col >= pendIndent)
                    {
                        var list = new List<object>(); pendMap[pendKey] = list;
                        frames.Add(new Frame { Indent = col, List = list });
                    }
                    else if (!dash && col > pendIndent)
                    {
                        var m = new Dictionary<string, object>(); pendMap[pendKey] = m;
                        frames.Add(new Frame { Indent = col, Map = m });
                    }
                    else pendMap[pendKey] = "";
                    pendKey = null;
                }

                if (dash)
                {
                    while (frames.Count > 1 && (Top(frames).Indent > col || (Top(frames).Indent == col && Top(frames).List == null))) frames.RemoveAt(frames.Count - 1);
                    var top = Top(frames);
                    if (top.List == null || top.Indent != col) continue;     // not something we understand
                    string rest = s.Length > 2 ? s.Substring(2).Trim() : "";
                    if (rest.IndexOf(':') < 0) { top.List.Add(Unquote(rest)); continue; }
                    var item = new Dictionary<string, object>();
                    top.List.Add(item);
                    frames.Add(new Frame { Indent = col + 2, Map = item });
                    KeyValue(item, rest, col + 2, ref pendMap, ref pendKey, ref pendIndent);
                }
                else
                {
                    while (frames.Count > 1 && (Top(frames).Indent > col || (Top(frames).Indent == col && Top(frames).List != null))) frames.RemoveAt(frames.Count - 1);
                    var top = Top(frames);
                    if (top.Map == null) continue;
                    KeyValue(top.Map, s, col, ref pendMap, ref pendKey, ref pendIndent);
                }
            }
            if (pendKey != null) pendMap[pendKey] = "";
            return root;
        }

        static Frame Top(List<Frame> f) { return f[f.Count - 1]; }

        static void KeyValue(Dictionary<string, object> map, string s, int col, ref Dictionary<string, object> pendMap, ref string pendKey, ref int pendIndent)
        {
            int c = s.IndexOf(':');
            if (c <= 0) return;
            string key = s.Substring(0, c).Trim(), val = s.Substring(c + 1).Trim();
            if (val.Length == 0) { pendMap = map; pendKey = key; pendIndent = col; return; }
            map[key] = Unquote(val);
        }

        static string Unquote(string v)
        {
            if (v.Length >= 2 && ((v[0] == '"' && v[v.Length - 1] == '"') || (v[0] == '\'' && v[v.Length - 1] == '\'')))
                return v.Substring(1, v.Length - 2);
            return v;
        }

        // ---------------------------------------------------------------- reading
        public static object Get(object node, params string[] path)
        {
            foreach (var p in path)
            {
                var m = node as Dictionary<string, object>;
                if (m == null || !m.TryGetValue(p, out node)) return null;
            }
            return node;
        }
        public static Dictionary<string, object> Map(object node, params string[] path) { return Get(node, path) as Dictionary<string, object>; }
        public static List<object> List(object node, params string[] path) { return Get(node, path) as List<object> ?? new List<object>(); }
        public static string Str(object node, params string[] path) { return Get(node, path) as string ?? ""; }
        public static long Long(object node, string key, long fallback)
        {
            long v;
            return long.TryParse(Str(node, key), NumberStyles.Integer, CultureInfo.InvariantCulture, out v) ? v : fallback;
        }
        public static int Int(object node, string key, int fallback) { return (int)Long(node, key, fallback); }
    }
}
