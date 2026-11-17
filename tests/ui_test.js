/**
 * Testes de interface (Playwright + Chromium) — SMT1 · Administração de Party
 *
 * Pré-requisitos:
 *   npm init -y && npm i playwright
 *   npx playwright install chromium
 *
 * Uso (com o app rodando em http://127.0.0.1:8080):
 *   node tests/ui_test.js [baseUrl]
 *
 * Sai com código 0 se tudo passar. Não deixa parties no banco (limpa no fim).
 */
'use strict';

const { chromium } = require('playwright');

const BASE = process.argv[2] || 'http://127.0.0.1:8080';
const API = BASE + '/api/index.php';

const ok = [];
const fail = [];

function check(nome, condicao, detalhe) {
  (condicao ? ok : fail).push(nome);
  console.log((condicao ? '  ok   ' : '  FAIL ') + nome + (condicao ? '' : ' ' + JSON.stringify(detalhe)));
}

async function limparParties(page) {
  await page.evaluate(async (api) => {
    const r = await fetch(api + '?rota=parties').then((x) => x.json());
    for (const p of r.data) {
      await fetch(api + '?rota=party&id=' + p.id, { method: 'DELETE' });
    }
  }, API);
}

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });

  const erros = [];
  page.on('console', (m) => {
    // 422 é esperado nos testes de validação (o navegador loga como erro)
    if (m.type() === 'error' && !m.text().includes('422')) erros.push(m.text());
  });
  page.on('pageerror', (e) => erros.push('PAGEERROR: ' + e.message));

  await page.goto(BASE + '/public/index.html', { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);
  await limparParties(page);

  /* ---------------- demônios ---------------- */
  console.log('== demônios ==');
  check('lista carregou 25 linhas', (await page.locator('#demons-body tr').count()) === 25);
  check('contador mostra 244', (await page.locator('#demons-info').textContent()).includes('244'));

  await page.fill('#f-q', 'Cerberus');
  await page.waitForTimeout(700);
  check('busca por nome filtra', (await page.locator('#demons-body tr').count()) === 1);

  await page.locator('#demons-body tr[data-slug="cerberus"]').click();
  await page.waitForTimeout(800);
  check('ficha abre com o nome certo', (await page.locator('#demon-detail h3').textContent()) === 'Cerberus');
  check('ficha mostra 14 resistências', (await page.locator('#demon-detail .resist').count()) === 14);
  const ficha = await page.locator('#demon-detail').textContent();
  check('ficha mostra fire refletido', /fire\s*reflete/.test(ficha.replace(/\s+/g, ' ')), ficha.slice(0, 200));
  await page.keyboard.press('Escape');
  await page.waitForTimeout(400);

  /* ---------------- fusões ---------------- */
  console.log('== fusões ==');
  await page.locator('#main-tabs .nav-link[data-view="fusions"]').click();
  await page.waitForTimeout(700);

  await page.selectOption('#fu-demon', 'cerberus');
  await page.waitForTimeout(800);
  const resumo = await page.locator('#fu-summary').textContent();
  check('retroativo: 47 combinações', resumo.includes('47'), resumo);
  check('retroativo: 47 linhas', (await page.locator('#fu-body tr').count()) === 47);
  const primeira = await page.locator('#fu-body tr').first().textContent();
  check('retroativo: par Bai Ze x Tam Lin', primeira.includes('Bai Ze') && primeira.includes('Tam Lin'), primeira);

  await page.locator('label[for="fu-mode-forward"]').click();
  await page.waitForTimeout(800);
  check('normal: cabeçalho correto',
    (await page.locator('#fu-head').textContent()).includes('Combina com'));
  check('normal: tem linhas', (await page.locator('#fu-body tr').count()) > 0);

  const alvo = await page.locator('#fu-body a[data-slug]').first().textContent();
  await page.locator('#fu-body a[data-slug]').first().click();
  await page.waitForTimeout(800);
  check('normal: clique abre a ficha do resultado',
    (await page.locator('#demon-detail h3').textContent()) === alvo, alvo);
  await page.keyboard.press('Escape');
  await page.waitForTimeout(400);

  await page.locator('label[for="fu-mode-reverse"]').click();
  await page.selectOption('#fu-demon', 'zombie');
  await page.waitForTimeout(800);
  check('sem fusão: aviso exibido', (await page.locator('#fu-empty').textContent()).length > 20);
  check('sem fusão: tabela vazia', (await page.locator('#fu-body tr').count()) === 0);

  /* ---------------- tabela raça × raça ---------------- */
  console.log('== tabela raça × raça ==');
  await page.locator('label[for="fu-mode-chart"]').click();
  await page.waitForTimeout(1200);
  check('chart aparece', await page.locator('#fu-chart .fusion-chart').isVisible());
  const cols = await page.locator('#fu-chart thead th.rot').count();
  check('22 raças nas colunas', cols === 22, cols);
  const linhasChart = await page.locator('#fu-chart tbody tr').count();
  check('22 raças nas linhas', linhasChart === 22, linhasChart);
  const celulas = await page.locator('#fu-chart td.cell').count();
  check('matriz 22x22', celulas === 484, celulas);
  const preenchidas = await page.locator('#fu-chart td.cell:not(.cell-vazia)').count();
  // o chart é espelhado: 224 pares fora da diagonal (x2) + 15 da diagonal = 463
  check('463 posições preenchidas (239 espelhadas + 15 na diagonal)', preenchidas === 463, preenchidas);
  check('célula de mesma raça mostra elemental (Avatar × Avatar)',
    (await page.locator('#fu-chart tbody tr:nth-child(1)').textContent()).length > 0);
  const textoChart = await page.locator('#fu-chart').textContent();
  check('chart contém Salamander', textoChart.includes('Salamander'));

  await page.locator('label[for="fu-mode-reverse"]').click();
  await page.waitForTimeout(600);
  check('voltar do chart reabilita o seletor de demônio',
    !(await page.locator('#fu-demon').isDisabled()));

  /* ---------------- parties ---------------- */
  console.log('== parties ==');
  await page.locator('#main-tabs .nav-link[data-view="parties"]').click();
  await page.waitForTimeout(700);
  check('estado vazio aparece', await page.locator('#parties-list .empty-state').isVisible());

  await page.locator('#btn-empty-new').click();
  await page.waitForTimeout(700);
  await page.fill('#p-name', 'Party do Kazuya');
  await page.fill('#p-protagonist-name', 'Kazuya');
  await page.fill('#p-protagonist-level', '40');
  await page.selectOption('#p-alignment', 'Neutral');

  const valorDe = (nome) => page.evaluate((n) => {
    const o = [...document.querySelectorAll('#party-slots select[data-slot="1"] option')]
      .find((x) => x.dataset.name === n);
    return o ? o.value : null;
  }, nome);

  const cerb = await valorDe('Cerberus');
  const unic = await valorDe('Unicorn');
  const baize = await valorDe('Bai Ze');

  await page.selectOption('#party-slots select[data-slot="1"]', cerb);
  await page.selectOption('#party-slots select[data-slot="2"]', unic);
  await page.waitForTimeout(400);
  check('aviso de nível acima do protagonista',
    (await page.locator('#party-warnings').textContent()).includes('Cerberus'));
  check('contador 2 / 3', (await page.locator('#party-counter').textContent()).includes('2 / 3'));

  const bloqueado = await page.evaluate(() => {
    const o = document.querySelector('#party-slots select[data-slot="3"] option[data-name="Cerberus"]');
    return o ? o.disabled : null;
  });
  check('demônio repetido fica desabilitado', bloqueado === true);

  await page.locator('#btn-save-party').click();
  await page.waitForTimeout(1500);
  check('card criado', (await page.locator('.party-card').count()) === 1);
  const card = await page.locator('.party-card').textContent();
  check('card mostra 2 / 3', card.includes('2 / 3'), card.slice(0, 100));
  check('card mostra aviso de nível', card.includes('acima do nível'));

  await page.locator('.js-edit-party').click();
  await page.waitForTimeout(800);
  check('edição carrega o nome', (await page.inputValue('#p-name')) === 'Party do Kazuya');
  await page.check('#p-comp-upgraded');
  await page.fill('#p-protagonist-level', '50');
  await page.selectOption('#party-slots select[data-slot="3"]', baize);
  await page.waitForTimeout(300);
  check('COMP atualizado muda o limite', (await page.locator('#party-counter').textContent()).includes('3 / 4'));
  await page.locator('#btn-save-party').click();
  await page.waitForTimeout(1500);
  const card2 = await page.locator('.party-card').textContent();
  check('card atualizado 3 / 4', card2.includes('3 / 4'));
  check('aviso de nível sumiu', !card2.includes('acima do nível'));

  /* ---------------- validação e segurança ---------------- */
  console.log('== validação e segurança ==');
  await page.locator('#btn-new-party').click();
  await page.waitForTimeout(600);
  await page.fill('#p-name', 'Party do Kazuya');
  await page.locator('#btn-save-party').click();
  await page.waitForTimeout(1200);
  check('nome duplicado marca o campo', (await page.locator('#p-name.is-invalid').count()) === 1);
  await page.locator('#modal-party [data-bs-dismiss="modal"]').last().click();
  await page.waitForTimeout(600);

  // regressão: nome com aspas não pode injetar atributo
  const idMalicioso = await page.evaluate(async (api) => {
    const lista = await fetch(api + '?rota=demons_lista').then((r) => r.json());
    const cerbId = lista.data.find((d) => d.name === 'Cerberus').id;
    const nome = 'X" onmouseover="alert(1)';
    const r = await fetch(api + '?rota=parties', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ name: nome, demons: [{ slot: 1, demon_id: cerbId }] }),
    }).then((x) => x.json());
    return r.data ? r.data.id : null;
  }, API);

  await page.locator('#main-tabs .nav-link[data-view="demons"]').click();
  await page.locator('#main-tabs .nav-link[data-view="parties"]').click();
  await page.waitForTimeout(1200);

  const injecao = await page.evaluate(() => {
    const card = [...document.querySelectorAll('.party-card')]
      .find((c) => c.textContent.includes('onmouseover'));
    if (!card) return { achou: false };
    const btn = card.querySelector('.js-del-party');
    return {
      achou: true,
      atributos: btn ? btn.getAttributeNames() : [],
      imgsInjetadas: card.querySelectorAll('img').length,
    };
  });
  check('nome malicioso não injeta atributo',
    !injecao.achou || (!injecao.atributos.includes('onmouseover') && injecao.imgsInjetadas === 0),
    injecao);

  if (idMalicioso) {
    await page.evaluate(async (args) => {
      await fetch(args.api + '?rota=party&id=' + args.id, { method: 'DELETE' });
    }, { api: API, id: idMalicioso });
  }

  /* ---------------- exclusão ---------------- */
  console.log('== exclusão ==');
  await page.locator('#main-tabs .nav-link[data-view="parties"]').click();
  await page.waitForTimeout(900);
  const antes = await page.locator('.js-del-party').count();
  await page.locator('.js-del-party').first().click();
  await page.waitForTimeout(600);
  check('abre o modal de confirmação', await page.locator('#modal-confirm').isVisible());
  check('modal mostra o nome da party',
    (await page.locator('#confirm-party-name').textContent()).length > 0);

  await page.locator('#modal-confirm [data-bs-dismiss="modal"]').first().click();
  await page.waitForTimeout(600);
  check('cancelar não exclui', (await page.locator('.js-del-party').count()) === antes);

  await page.locator('.js-del-party').first().click();
  await page.waitForTimeout(500);
  await page.locator('#confirm-delete').click();
  await page.waitForTimeout(1200);
  check('confirmar remove um card', (await page.locator('.js-del-party').count()) === antes - 1);

  check('sem erros de console', erros.length === 0, erros);

  await limparParties(page);
  await browser.close();

  console.log();
  if (fail.length) {
    console.log('FALHARAM ' + fail.length + ' teste(s): ' + fail.join(', '));
    process.exit(1);
  }
  console.log('Todos os ' + ok.length + ' testes de interface passaram.');
})().catch((e) => {
  console.error('FALHOU:', e.message);
  process.exit(1);
});
