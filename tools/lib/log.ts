/* A run log for the tools. Every line is appended to a file with a timestamp, so a failed run can
   be read back afterwards; the console gets the normal messages, and the detail too in debug mode. */
import { appendFileSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

export interface Log {
  readonly file: string;
  /** A normal message: console and file. */
  say(s: string): void;
  /** Step-by-step detail: file always, console only in debug mode. */
  detail(s: string): void;
  /** An error with whatever was known at the time: file always, with the stack. */
  fail(e: unknown, context?: Record<string, unknown>): void;
}

export function openLog(file: string, debug: boolean): Log {
  const write = (level: string, s: string) => {
    try {
      mkdirSync(dirname(file), { recursive: true });
      const at = new Date().toISOString();
      appendFileSync(file, s.split('\n').map(l => `${at} ${level} ${l}`).join('\n') + '\n');
    } catch { /* a locked or full disk mustn't stop the run */ }
  };
  return {
    file,
    say(s) { console.log(s); write('info ', s); },
    detail(s) { if (debug) console.log(s); write('debug', s); },
    fail(e, context) {
      const err = e instanceof Error ? e : new Error(String(e));
      write('ERROR', err.stack ?? err.message);
      if (err.cause instanceof Error && err.cause.stack) write('ERROR', `caused by: ${err.cause.stack}`);
      if (context) write('ERROR', `context: ${JSON.stringify(context)}`);
    },
  };
}
