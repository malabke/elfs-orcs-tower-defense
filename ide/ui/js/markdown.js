// A small, safe Markdown renderer for documentation (extensions' doc/index.md): headings, paragraphs, bullet and numbered lists, tables,
// code blocks, `code`, **bold**, *italic*, [links](https://…). DOM nodes only, never innerHTML: what a package's author wrote cannot run.
import { el } from './ui.js';

const INLINE = /(`[^`]+`|\*\*[^*]+\*\*|\*[^*\s][^*]*\*|\[[^\]]+\]\([^)\s]+\))/g;

/** The inline parts of a line as nodes; onLink(url) opens an http(s) link, other targets stay plain text. */
export function inline(text, onLink) {
  const out = [];
  for (const [n, chunk] of text.split(INLINE).entries()) {
    if (n % 2 === 0) { if (chunk) out.push(chunk); continue; }
    if (chunk.startsWith('`')) out.push(el('code', {}, chunk.slice(1, -1)));
    else if (chunk.startsWith('**')) out.push(el('b', {}, chunk.slice(2, -2)));
    else if (chunk.startsWith('*')) out.push(el('i', {}, chunk.slice(1, -1)));
    else {
      const m = /^\[([^\]]+)\]\(([^)\s]+)\)$/.exec(chunk);
      if (m && /^https?:\/\//.test(m[2]) && onLink) out.push(el('a', { href: '#', onclick: (e) => { e.preventDefault(); onLink(m[2]); } }, m[1]));
      else out.push(m ? m[1] : chunk);
    }
  }
  return out;
}

/** @param {string} text @param {(url: string) => void} [onLink] @returns {Node[]} */
export function renderMarkdown(text, onLink) {
  const nodes = [];
  const lines = String(text || '').replace(/\r\n?/g, '\n').split('\n');
  let i = 0;
  while (i < lines.length) {
    const line = lines[i];
    if (/^```/.test(line)) {
      const code = [];
      for (i++; i < lines.length && !/^```/.test(lines[i]); i++) code.push(lines[i]);
      i++;
      nodes.push(el('pre.claude-code', {}, code.join('\n')));
    } else if (/^#{1,4}\s/.test(line)) {
      const level = /^#+/.exec(line)[0].length;
      nodes.push(el(`h${Math.min(5, level + 1)}`, {}, ...inline(line.replace(/^#+\s*/, ''), onLink)));
      i++;
    } else if (/^\s*\|.*\|\s*$/.test(line) && /^\s*\|[\s:|-]+\|\s*$/.test(lines[i + 1] || '')) {
      const cells = (row) => row.trim().replace(/^\||\|$/g, '').split('|').map((c) => c.trim());
      const head = cells(line);
      const rows = [];
      for (i += 2; i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i]); i++) rows.push(cells(lines[i]));
      nodes.push(el('table.md-table', {}, el('thead', {}, el('tr', {}, ...head.map((c) => el('th', {}, ...inline(c, onLink))))),
        el('tbody', {}, ...rows.map((r) => el('tr', {}, ...r.map((c) => el('td', {}, ...inline(c, onLink))))))));
    } else if (/^\s*([-*]|\d+\.)\s+/.test(line)) {
      const ordered = /^\s*\d+\./.test(line);
      const items = [];
      for (; i < lines.length && /^\s*([-*]|\d+\.)\s+/.test(lines[i]); i++) {
        let item = lines[i].replace(/^\s*([-*]|\d+\.)\s+/, '');
        while (i + 1 < lines.length && /^\s{2,}\S/.test(lines[i + 1]) && !/^\s*([-*]|\d+\.)\s+/.test(lines[i + 1])) item += ' ' + lines[++i].trim();
        items.push(el('li', {}, ...inline(item, onLink)));
      }
      nodes.push(el(ordered ? 'ol' : 'ul', {}, ...items));
    } else if (/^\s*([-*_])\s*\1\s*\1[-*_\s]*$/.test(line)) {
      nodes.push(el('hr'));
      i++;
    } else if (/^>\s?/.test(line)) {
      const quote = [];
      for (; i < lines.length && /^>\s?/.test(lines[i]); i++) quote.push(lines[i].replace(/^>\s?/, ''));
      nodes.push(el('blockquote', {}, ...renderMarkdown(quote.join('\n'), onLink)));
    } else if (!line.trim()) {
      i++;
    } else {
      const para = [];
      for (; i < lines.length && lines[i].trim() && !/^(```|>|#{1,4}\s|\s*([-*]|\d+\.)\s+|\s*\|)/.test(lines[i]); i++) para.push(lines[i].trim());
      nodes.push(el('p', {}, ...inline(para.join(' '), onLink)));
    }
  }
  return nodes;
}
