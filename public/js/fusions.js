/* ------------------------------------------------------------------
   SMT1 · Administração de Party — listagem de fusões
   Dois modos:
     retroativo ("como obter")  -> o que produz o demônio escolhido
     normal ("o que sai dele")  -> o que o demônio produz com outros
   ------------------------------------------------------------------ */
'use strict';

window.Fusions = {
  modo: 'reverse',
  demonios: [],
  carregado: false,

  init() {
    $('input[name="fu-mode"]').on('change', () => {
      this.modo = $('input[name="fu-mode"]:checked').val();
      this.atualizarDica();
      this.buscar();
    });

    $('#fu-demon').on('change', () => this.buscar());

    $('#fu-table').on('click', 'tr[data-slug]', function () {
      Demons.open($(this).data('slug'));
    });
  },

  async carregarLista() {
    if (this.carregado) return;
    try {
      const { data } = await api('GET', { rota: 'demons_lista' });
      this.demonios = data;
      const opcoes = data
        .slice()
        .sort((a, b) => a.name.localeCompare(b.name))
        .map((d) =>
          '<option value="' + esc(d.slug) + '">' +
          esc(d.name) + ' · ' + esc(d.race) + ' · Nv ' + fmtLevel(d.level) +
          '</option>'
        )
        .join('');
      $('#fu-demon').append(opcoes);
      this.carregado = true;
    } catch (e) {
      toast('Não foi possível carregar a lista de demônios: ' + erroLegivel(e), 'error');
    }
  },

  atualizarDica() {
    const dicas = {
      reverse: 'Escolha o demônio que você quer <strong>obter</strong>.',
      forward: 'Escolha o demônio que você <strong>tem</strong>, para ver o que sai dele.',
      chart: 'Cruze a raça de um ingrediente com a do outro. Toque numa célula para ver os demônios daquela raça.',
    };
    $('#fu-hint').html(dicas[this.modo] || dicas.reverse);
  },

  /** Abre a aba já com um demônio e um modo (usado pela ficha). */
  async abrir(slug, modo) {
    showView('fusions');
    await this.carregarLista();

    this.modo = modo === 'forward' ? 'forward' : 'reverse';
    $('#fu-mode-forward').prop('checked', this.modo === 'forward');
    $('#fu-mode-reverse').prop('checked', this.modo === 'reverse');
    $('#fu-demon').val(slug);
    this.atualizarDica();
    this.buscar();
  },

  async buscar() {
    if (this.modo === 'chart') {
      this.buscarChart();
      return;
    }

    const slug = $('#fu-demon').val();
    $('#fu-specials').empty();
    $('#fu-chart').prop('hidden', true).empty();

    if (!slug) {
      $('#fu-summary').empty();
      $('#fu-empty').empty();
      $('#fu-head').empty();
      $('#fu-body').html('<tr><td class="text-center text-secondary py-4">Escolha um demônio acima.</td></tr>');
      return;
    }

    const rota = this.modo === 'forward' ? 'demon_fusions_out' : 'demon_fusions';
    $('#fu-demon').prop('disabled', false);
    $('#fu-head').empty();
    $('#fu-body').html('<tr><td class="text-center text-secondary py-4">Carregando…</td></tr>');

    try {
      const { data, meta } = await api('GET', { rota: rota, slug: slug });
      this.render(data, meta);
    } catch (e) {
      $('#fu-body').html('<tr><td class="text-center text-danger py-4">' + esc(erroLegivel(e)) + '</td></tr>');
    }
  },

  /* ---------------- tabela raça × raça ---------------- */

  async buscarChart() {
    $('#fu-summary').empty();
    $('#fu-empty').empty();
    $('#fu-specials').empty();
    $('#fu-head').empty();
    $('#fu-body').empty();
    $('#fu-demon').prop('disabled', true);

    const $alvo = $('#fu-chart').prop('hidden', false);
    $alvo.html('<p class="text-secondary text-center py-4 mb-0">Carregando…</p>');

    try {
      if (!this.chartData) {
        this.chartData = (await api('GET', { rota: 'fusion_chart' })).data;
      }
      this.renderChart(this.chartData);
    } catch (e) {
      $alvo.html('<p class="text-danger text-center py-4 mb-0">' + esc(erroLegivel(e)) + '</p>');
    }
  },

  renderChart(dados) {
    const racas = dados.races.map((r) => r.name);
    const mapa = {};

    dados.cells.forEach((c) => {
      mapa[c.race_a + '|' + c.race_b] = c;
      mapa[c.race_b + '|' + c.race_a] = c;   // a fusão é simétrica
    });

    const classe = { race: '', species: 'cell-species', element_demon: 'cell-element' };

    let html = '<div class="table-responsive chart-wrap">' +
      '<table class="table table-sm fusion-chart"><thead><tr><th class="corner">raça × raça</th>';

    racas.forEach((r) => { html += '<th class="rot">' + esc(r) + '</th>'; });
    html += '</tr></thead><tbody>';

    racas.forEach((linha) => {
      html += '<tr><th class="rot row-head">' + esc(linha) + '</th>';
      racas.forEach((coluna) => {
        const c = mapa[linha + '|' + coluna];
        if (!c) {
          html += '<td class="cell cell-vazia">—</td>';
        } else {
          const titulo = linha + ' × ' + coluna + ' → ' + c.result;
          const attr = c.demon_slug ? ' data-slug="' + esc(c.demon_slug) + '"' : '';
          html += '<td class="cell ' + (classe[c.type] || '') + '" title="' + esc(titulo) + '"' +
            attr + '>' + esc(c.result) + '</td>';
        }
      });
      html += '</tr>';
    });

    html += '</tbody></table></div>' +
      '<p class="small text-secondary mt-2 mb-1">' +
      '<span class="badge me-1 key key-race">raça</span> o cruzamento gera uma raça · ' +
      '<span class="badge me-1 key key-species">grupo</span> gera um grupo (species) · ' +
      '<span class="badge me-1 key key-element">elemental</span> gera o elemental · ' +
      'células na diagonal (raça × ela mesma) também geram elemental.' +
      '</p>' +
      '<p class="small text-secondary mb-0">' +
      '<span class="key key-vazia">—</span> essa combinação não tem fusão no jogo. ' +
      'Passe o mouse para destacar a linha e a coluna; clique num elemental para abrir a ficha.' +
      '</p>';

    $('#fu-chart').html(html);

    // passa o mouse: realça linha e coluna
    $('#fu-chart').on('mouseenter', 'td.cell', function () {
      const $t = $(this);
      const idx = $t.index();
      const $tab = $t.closest('table');
      $tab.find('td.cell, th.rot').removeClass('hilite');
      $tab.find('tr').eq($t.parent().index() + 1).find('td.cell, th.rot').addClass('hilite');
      $tab.find('tr').each(function () {
        $(this).find('td.cell, th.rot').eq(idx).addClass('hilite');
      });
    });
    $('#fu-chart').on('mouseleave', 'table', function () {
      $(this).find('.hilite').removeClass('hilite');
    });

    // célula de elemental abre a ficha do demônio
    $('#fu-chart').on('click', 'td.cell[data-slug]', function () {
      Demons.open($(this).data('slug'));
    });
  },

  rotuloRegra(regra) {
    const mapa = {
      normal: 'raça × raça',
      species: 'mesmo grupo',
      element: 'elemental',
      dark: 'dark',
    };
    return '<span class="badge badge-soft">' + esc(mapa[regra] || regra) + '</span>';
  },

  render(rows, meta) {
    const alvo = meta.demon;

    // ---- resumo -------------------------------------------------------
    const r = meta.por_regra || {};
    const partes = [];
    if (r.normal) partes.push(r.normal + ' por raça');
    if (r.species) partes.push(r.species + ' por grupo');
    if (r.element) partes.push(r.element + ' com elemental');

    $('#fu-summary').html(
      '<div class="d-flex flex-wrap align-items-center gap-2">' +
      '<span class="badge badge-soft">' + esc(alvo.name) + ' · ' + esc(alvo.race) + ' · Nv ' + fmtLevel(alvo.level) + '</span>' +
      '<span class="badge badge-soft">' + meta.total + ' combinação(ões)</span>' +
      (partes.length ? '<span class="small text-secondary">' + esc(partes.join(' · ')) + '</span>' : '') +
      '</div>'
    );

    // ---- sem fusão: explica como obter --------------------------------
    if (!rows.length) {
      $('#fu-empty').html(this.mensagemVazio(meta, alvo));
      $('#fu-head').empty();
      $('#fu-body').empty();
      this.renderEspeciais(meta.receitas, alvo);
      return;
    }

    $('#fu-empty').empty();

    // ---- tabela -------------------------------------------------------
    if (this.modo === 'forward') {
      $('#fu-head').html(
        '<tr><th>Resultado</th><th>Raça</th><th class="text-end">Nv</th>' +
        '<th>Combina com</th><th>Raça</th><th class="text-end">Nv</th><th>Regra</th></tr>'
      );
      $('#fu-body').html(rows.map((f) => (
        '<tr>' +
        '<td class="fw-semibold"><a href="#" class="link-light text-decoration-none" data-slug="' + esc(f.result.slug) + '">' + esc(f.result.name) + '</a></td>' +
        '<td><span class="race-pill">' + esc(f.result.race) + '</span></td>' +
        '<td class="text-end level-cell">' + fmtLevel(f.result.level) + '</td>' +
        '<td>' + esc(f.other.name) + '</td>' +
        '<td><span class="race-pill">' + esc(f.other.race) + '</span></td>' +
        '<td class="text-end level-cell">' + fmtLevel(f.other.level) + '</td>' +
        '<td>' + this.rotuloRegra(f.rule) + '</td>' +
        '</tr>'
      )).join(''));
    } else {
      $('#fu-head').html(
        '<tr><th>Ingrediente 1</th><th>Raça</th><th class="text-end">Nv</th>' +
        '<th>Ingrediente 2</th><th>Raça</th><th class="text-end">Nv</th><th>Regra</th></tr>'
      );
      $('#fu-body').html(rows.map((f) => (
        '<tr>' +
        '<td class="fw-semibold">' + esc(f.ingredient_a.name) + '</td>' +
        '<td><span class="race-pill">' + esc(f.ingredient_a.race) + '</span></td>' +
        '<td class="text-end level-cell">' + fmtLevel(f.ingredient_a.level) + '</td>' +
        '<td>' + esc(f.ingredient_b.name) + '</td>' +
        '<td><span class="race-pill">' + esc(f.ingredient_b.race) + '</span></td>' +
        '<td class="text-end level-cell">' + fmtLevel(f.ingredient_b.level) + '</td>' +
        '<td>' + this.rotuloRegra(f.rule) + '</td>' +
        '</tr>'
      )).join(''));
    }

    // clicar no nome do resultado (modo normal) abre a ficha
    $('#fu-body a[data-slug]').on('click', function (ev) {
      ev.preventDefault();
      Demons.open($(this).data('slug'));
    });

    this.renderEspeciais(meta.receitas, alvo);
  },

  mensagemVazio(meta, alvo) {
    const receitas = meta.receitas || [];
    const porTipo = {
      receita_especial: {
        titulo: 'Não sai de nenhuma combinação livre',
        texto: 'Este demônio só é obtido por <strong>receita especial</strong> (par ou trio fixo). Veja abaixo.',
        classe: 'badge-warn',
      },
      recrutamento: {
        titulo: 'Não sai de fusão',
        texto: 'Este demônio entra na party por <strong>recrutamento</strong> (negociação em batalha), não por fusão.',
        classe: 'badge-warn',
      },
      inimigo: {
        titulo: 'Não é obtenível',
        texto: 'Nenhuma combinação gera este demônio e ele não é recrutável: no jogo ele aparece apenas como ' +
               '<strong>chefe/inimigo</strong>.',
        classe: 'badge-danger-soft',
      },
    };

    const info = porTipo[meta.obtencao] || {
      titulo: 'Sem combinações',
      texto: 'Nenhuma fusão encontrada para este demônio.',
      classe: 'badge-warn',
    };

    return (
      '<div class="empty-state">' +
      '<p class="mb-2"><span class="badge ' + info.classe + '">' + esc(info.titulo) + '</span></p>' +
      '<p class="mb-1">' + info.texto + '</p>' +
      (receitas.length
        ? '<p class="small text-secondary mb-0">' + receitas.length + ' receita(s) especial(is) registrada(s) abaixo.</p>'
        : '') +
      '</div>'
    );
  },

  renderEspeciais(receitas, alvo) {
    if (!receitas || !receitas.length) {
      $('#fu-specials').empty();
      return;
    }

    const mapaLabels = {
      pair: 'par fixo',
      trio: 'trio fixo',
      prereq: 'pré-requisito',
      recruit: 'recrutamento',
    };

    const linhas = receitas.map((r) => (
      '<tr>' +
      '<td><span class="badge badge-soft">' + esc(mapaLabels[r.kind] || r.kind) + '</span></td>' +
      '<td>' + esc(r.recipe_text) + '</td>' +
      '</tr>'
    )).join('');

    $('#fu-specials').html(
      '<h6 class="text-uppercase text-secondary small">Receitas especiais de ' + esc(alvo.name) + '</h6>' +
      '<div class="table-responsive"><table class="table table-sm demons-table">' +
      '<thead><tr><th>Tipo</th><th>Receita</th></tr></thead><tbody>' + linhas + '</tbody></table></div>'
    );
  },
};
