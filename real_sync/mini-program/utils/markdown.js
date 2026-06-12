function renderMarkdown(content) {
  const text = String(content || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
  if (!text.trim()) return '';
  if (looksLikeHtml(text)) return text;

  const lines = text.split('\n');
  const html = [];
  let paragraph = [];
  let codeBlock = [];
  let inCodeBlock = false;

  const flushParagraph = () => {
    if (!paragraph.length) return;
    html.push(`<p>${renderInline(paragraph.join('<br/>'))}</p>`);
    paragraph = [];
  };

  const flushCodeBlock = () => {
    if (!codeBlock.length) return;
    html.push(`<p class="markdown-code-block">${codeBlock.map(escapeHtml).join('<br/>')}</p>`);
    codeBlock = [];
  };

  for (let i = 0; i < lines.length; i += 1) {
    const rawLine = lines[i] || '';
    const line = rawLine.trim();

    if (line.indexOf('```') === 0) {
      flushParagraph();
      if (inCodeBlock) flushCodeBlock();
      inCodeBlock = !inCodeBlock;
      continue;
    }

    if (inCodeBlock) {
      codeBlock.push(rawLine);
      continue;
    }

    if (!line) {
      flushParagraph();
      continue;
    }

    if (isTableLine(line)) {
      flushParagraph();
      const tableLines = [];
      while (i < lines.length && isTableLine((lines[i] || '').trim())) {
        tableLines.push((lines[i] || '').trim());
        i += 1;
      }
      i -= 1;
      html.push(renderTableLines(tableLines));
      continue;
    }

    const heading = line.match(/^(#{1,4})\s+(.+)$/);
    if (heading) {
      flushParagraph();
      const level = Math.min(heading[1].length, 3);
      html.push(`<h${level}>${renderInline(heading[2])}</h${level}>`);
      continue;
    }

    const quote = line.match(/^>\s*(.+)$/);
    if (quote) {
      flushParagraph();
      html.push(`<blockquote>${renderInline(quote[1])}</blockquote>`);
      continue;
    }

    const listItem = line.match(/^([-*]|\d+[.)])\s+(.+)$/);
    if (listItem) {
      flushParagraph();
      html.push(`<p class="markdown-list-line">• ${renderInline(listItem[2])}</p>`);
      continue;
    }

    if (/^[-*_]{3,}$/.test(line)) {
      flushParagraph();
      html.push('<hr/>');
      continue;
    }

    paragraph.push(escapeHtml(line));
  }

  flushParagraph();
  flushCodeBlock();
  return html.join('');
}

function renderTableLines(lines) {
  const rows = lines
    .map(line => line.split('|').map(cell => cell.trim()).filter(Boolean))
    .filter(row => row.length > 0 && !row.every(isTableDivider));

  if (rows.length <= 1) return '';
  const headers = rows[0];
  const bodyRows = rows.slice(1);
  return bodyRows.map(row => {
    const items = row.map((cell, index) => {
      const label = headers[index] || `项目${index + 1}`;
      return `<p><strong>${renderInline(label)}：</strong>${renderInline(cell)}</p>`;
    }).join('');
    return `<div class="markdown-table-block">${items}</div>`;
  }).join('');
}

function isTableLine(line) {
  return line.indexOf('|') >= 0 && line.split('|').filter(Boolean).length >= 2;
}

function isTableDivider(cell) {
  return /^:?-{3,}:?$/.test(String(cell || '').replace(/\s/g, ''));
}

function renderInline(text) {
  return escapeHtml(String(text || ''))
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/`(.+?)`/g, '<code>$1</code>');
}

function escapeHtml(text) {
  return String(text || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function looksLikeHtml(text) {
  return /<\/?(p|div|h[1-6]|br|ul|ol|li|table|tr|td|th|strong|em|span|blockquote|section)(\s|>|\/)/i.test(text);
}

module.exports = {
  renderMarkdown
};
