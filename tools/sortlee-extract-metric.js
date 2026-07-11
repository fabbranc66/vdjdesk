const fs = require('fs');
const http = require('http');

const [, , metricKey, metricNeedle, outputArgument] = process.argv;

if (!metricKey || !metricNeedle) {
  console.error('Uso: node tools/sortlee-extract-metric.js <chiave> <testo-metrica> [file-output]');
  process.exit(1);
}

const sleep = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

const getJson = (url) => new Promise((resolve, reject) => {
  http.get(url, (response) => {
    let body = '';
    response.on('data', (chunk) => { body += chunk; });
    response.on('end', () => resolve(JSON.parse(body)));
    response.on('error', reject);
  });
});

async function main() {
  const pages = await getJson('http://127.0.0.1:9223/json/list');
  const page = pages.find(({ url }) => url.includes('sortlee.com/playlist/'));
  if (!page) throw new Error('Playlist Sortlee non aperta');

  const socket = new WebSocket(page.webSocketDebuggerUrl);
  const pending = new Map();
  let sequence = 0;

  socket.onmessage = ({ data }) => {
    const message = JSON.parse(data);
    if (!message.id || !pending.has(message.id)) return;
    pending.get(message.id)(message);
    pending.delete(message.id);
  };

  await new Promise((resolve, reject) => {
    socket.onopen = resolve;
    socket.onerror = reject;
  });

  const evaluate = (expression) => new Promise((resolve, reject) => {
    const id = ++sequence;
    pending.set(id, (message) => {
      if (message.error) reject(new Error(message.error.message));
      else resolve(message.result.result.value);
    });
    socket.send(JSON.stringify({
      id,
      method: 'Runtime.evaluate',
      params: { expression, returnByValue: true, awaitPromise: true },
    }));
  });

  const waitFor = async (expression, timeout = 35000) => {
    const deadline = Date.now() + timeout;
    while (Date.now() < deadline) {
      const value = await evaluate(expression);
      if (value) return value;
      await sleep(300);
    }
    throw new Error(`Timeout: ${expression.slice(0, 100)}`);
  };

  const needle = JSON.stringify(metricNeedle);
  const metricAlreadyVisible = await evaluate(
    `([...document.querySelectorAll('th')].some((cell) => cell.innerText.includes(${needle})))`,
  );

  if (!metricAlreadyVisible) {
    let clicked = await evaluate(`(() => {
      const button = [...document.querySelectorAll('button.mat-button-toggle-button')]
        .find((item) => item.innerText.includes(${needle}));
      if (!button) return false;
      button.click();
      return true;
    })()`);
    if (!clicked) {
      await evaluate(`(() => {
        const header = [...document.querySelectorAll('mat-expansion-panel-header')]
          .find((item) => item.innerText.trim().startsWith('3.'));
        if (!header) return false;
        header.click();
        return true;
      })()`);
      await waitFor(`([...document.querySelectorAll('button.mat-button-toggle-button')]
        .some((item) => item.innerText.includes(${needle})))`);
      clicked = await evaluate(`(() => {
        const button = [...document.querySelectorAll('button.mat-button-toggle-button')]
          .find((item) => item.innerText.includes(${needle}));
        if (!button) return false;
        button.click();
        return true;
      })()`);
    }
    if (!clicked) throw new Error(`Metrica non trovata: ${metricNeedle}`);

    await waitFor(`(() => {
      const heading = [...document.querySelectorAll('h3')]
        .find((item) => item.textContent.includes('4.'));
      return !!heading?.closest('mat-expansion-panel')
        ?.querySelector('button.mat-button-toggle-button');
    })()`);
    await sleep(700);
    await evaluate(`(() => {
      const heading = [...document.querySelectorAll('h3')]
        .find((item) => item.textContent.includes('4.'));
      const button = heading?.closest('mat-expansion-panel')
        ?.querySelector('button.mat-button-toggle-button');
      if (!button) return false;
      button.click();
      return true;
    })()`);
    await waitFor(`(
      [...document.querySelectorAll('th')].some((cell) => cell.innerText.includes(${needle}))
      && document.querySelectorAll('tbody tr').length > 0
    )`);
  }

  const movedToFirstPage = await evaluate(`(() => {
    const button = document.querySelector('.mat-mdc-paginator-navigation-first');
    if (!button || button.disabled) return false;
    button.click();
    return true;
  })()`);
  if (movedToFirstPage) {
    await waitFor("document.querySelector('.mat-mdc-paginator-range-label')?.innerText.trim().startsWith('1 /')");
  }

  const rows = [];
  const pageCounts = [];
  while (true) {
    const previousRange = await evaluate(
      "document.querySelector('.mat-mdc-paginator-range-label')?.innerText.trim()",
    );
    const batch = await evaluate(`([...document.querySelectorAll('tbody tr')].map((row) => {
      const cells = [...row.querySelectorAll('td')];
      const anchor = row.querySelector('a[href*="open.spotify.com/track/"]');
      const spotifyUrl = anchor?.href || '';
      return {
        position: Number(cells[0]?.innerText.trim()) || null,
        spotify_url: spotifyUrl,
        spotify_id: spotifyUrl.split('/').pop().split('?')[0],
        title: cells[4]?.innerText.trim() || '',
        value: cells[5]?.innerText.trim() || '',
      };
    }))`);
    rows.push(...batch);
    pageCounts.push(batch.length);

    const moved = await evaluate(`(() => {
      const button = document.querySelector('.mat-mdc-paginator-navigation-next');
      if (!button || button.disabled) return false;
      button.click();
      return true;
    })()`);
    if (!moved) break;
    await waitFor(
      `document.querySelector('.mat-mdc-paginator-range-label')?.innerText.trim() !== ${JSON.stringify(previousRange)}`,
    );
  }

  const outputPath = outputArgument || 'storage/sortlee-metrics.json';
  const playlistTitle = await evaluate('document.title');
  const existing = fs.existsSync(outputPath)
    ? JSON.parse(fs.readFileSync(outputPath, 'utf8'))
    : null;
  const output = existing?.url === page.url
    ? existing
    : { playlist: playlistTitle, url: page.url, metrics: {} };
  output.extracted_at = new Date().toISOString();
  output.metrics[metricKey] = {
    label: metricNeedle,
    total: rows.length,
    filled: rows.filter(({ value }) => value).length,
    page_counts: pageCounts,
    rows,
  };
  fs.writeFileSync(outputPath, JSON.stringify(output, null, 2));
  socket.close();
  console.log(`${metricKey}: ${rows.length} [${pageCounts.join('+')}] filled=${output.metrics[metricKey].filled}`);
}

main().catch((error) => {
  console.error(error.stack || error);
  process.exit(1);
});
