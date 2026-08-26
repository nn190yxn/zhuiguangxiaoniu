function renderMarkdown(content) {
  const text = normalizeBreaks(String(content || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n'));
  if (!text.trim()) return '';


  const lines = text.split('\n');
  const html = [];
  let paragraph = [];
  let codeBlock = [];
  let inCodeBlock = false;

  const flushParagraph = () => {
    if (!paragraph.length) return;
    paragraph.forEach(line => {
      html.push(`<p class="markdown-paragraph">${renderInline(line)}</p>`);
    });
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
      html.push(`<h${level} class="markdown-heading markdown-h${level}">${renderInline(heading[2])}</h${level}>`);
      continue;
    }

    const quote = line.match(/^>\s*(.+)$/);
    if (quote) {
      flushParagraph();
      html.push(`<blockquote class="markdown-quote">${renderInline(quote[1])}</blockquote>`);
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
      html.push('<hr class="markdown-divider"/>');
      continue;
    }

    paragraph.push(line);
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
      return `<div class="markdown-field"><span class="markdown-field-label">${renderInline(label)}</span><span class="markdown-field-value">${renderInline(cell)}</span></div>`;
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
  const value = String(text || '');
  const pattern = /(!?)\[([^\]]*)\]\(([^)\s]+)(?:\s+["'][^"']*["'])?\)/g;
  let html = '';
  let cursor = 0;
  let match;

  while ((match = pattern.exec(value)) !== null) {
    html += renderInlineText(value.slice(cursor, match.index));
    const isImage = match[1] === '!';
    const label = match[2];
    const url = safeMarkdownUrl(match[3], isImage);
    if (!url) {
      html += isImage
        ? `<span class="markdown-missing-image">[图片：${escapeHtml(label || '来源图片缺失')}]</span>`
        : renderInlineText(label);
    } else if (isImage) {
      html += `<img class="markdown-image" src="${escapeHtml(url)}" alt="${escapeHtml(label)}" />`;
    } else {
      html += `<a class="markdown-link" href="${escapeHtml(url)}">${renderInlineText(label)}</a>`;
    }
    cursor = match.index + match[0].length;
  }

  return html + renderInlineText(value.slice(cursor));
}

function renderInlineText(text) {
  return escapeHtml(String(text || ''))
    .replace(/\*\*(.+?)\*\*/g, '<strong class="markdown-strong">$1</strong>')
    .replace(/`(.+?)`/g, '<code class="markdown-code">$1</code>');
}

function safeMarkdownUrl(value, isImage) {
  const url = String(value || '').trim();
  if (!url || /[\u0000-\u001f\u007f\\]/.test(url) || url.startsWith('//')) return '';
  if (/^https:\/\//i.test(url)) return isImage ? '' : url;
  if (/^\/uploads\/knowledge\//i.test(url) && !url.includes('..')) return url;
  return '';
}

function escapeHtml(text) {
  return String(text || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function normalizeBreaks(text) {
  return String(text || '')
    .replace(/&lt;br\s*\/?&gt;/gi, '\n')
    .replace(/<br\s*\/?>/gi, '\n');
}

module.exports = {
  renderMarkdown
};
