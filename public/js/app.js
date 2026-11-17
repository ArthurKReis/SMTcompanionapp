/* ------------------------------------------------------------------
   SMT1 · Administração de Party — núcleo do front (jQuery + Bootstrap)
   Consome a API PHP em ../api/index.php (mesma origem, sem autenticação)
   ------------------------------------------------------------------ */
'use strict';

const API = '../api/index.php';

/* ==================== helpers ==================== */

/**
 * Chamada à API.
 * @param {string} method
 * @param {object|null} params  querystring
 * @param {object|null} body    corpo JSON
 */
function api(method, params, body) {
  // A rota vai SEMPRE na querystring; `body` é só o corpo JSON.
  let url = API;
  if (params) {
    const qs = $.param(params);
    if (qs) url += '?' + qs;
  }

  const settings = { url: url, method: method, dataType: 'json' };

  if (body !== undefined && body !== null) {
    settings.contentType = 'application/json; charset=utf-8';
    settings.data = JSON.stringify(body);
  }

  return new Promise((resolve, reject) => {
    $.ajax(settings)
      .done((payload) => resolve(payload))
      .fail((xhr) => {
        const erro = xhr.responseJSON && xhr.responseJSON.error
          ? xhr.responseJSON.error
          : { code: 'rede', message: 'Falha de comunicação com a API.' };
        reject(erro);
      });
  });
}

/**
 * Escapa texto para uso em conteúdo E em atributos HTML.
 * O .html() do jQuery escapa apenas &, < e > — aspas passam direto, e uma aspa
 * dentro de data-name="..." quebraria o atributo (injeção de atributo/XSS).
 */
function esc(value) {
  return String(value === null || value === undefined ? '' : value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function fmtLevel(value) {
  const n = Number(value);
  return Number.isInteger(n) ? String(n) : n.toFixed(1);
}

function toast(message, type) {
  const cls = type === 'error' ? 'text-bg-danger' : 'text-bg-success';
  const $t = $(
    '<div class="toast align-items-center border-0 ' + cls + '" role="alert">' +
    '<div class="d-flex"><div class="toast-body"></div>' +
    '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
    '</div></div>'
  );
  $t.find('.toast-body').text(message);
  $('#toasts').append($t);
  const inst = new bootstrap.Toast($t[0], { delay: 4200 });
  inst.show();
  $t.on('hidden.bs.toast', () => $t.remove());
}

/** Mensagem de erro da API -> texto legível (inclui erros de validação por campo). */
function erroLegivel(erro) {
  if (!erro) return 'Erro desconhecido.';
  if (erro.details && typeof erro.details === 'object') {
    const campos = Object.keys(erro.details);
    if (campos.length) {
      return erro.message + ' (' + campos.map((c) => c + ': ' + erro.details[c]).join('; ') + ')';
    }
  }
  return erro.message || erro.code || 'Erro desconhecido.';
}

/* ==================== navegação por abas ==================== */

function showView(name) {
  $('#main-tabs .nav-link').removeClass('active');
  $('#main-tabs .nav-link[data-view="' + name + '"]').addClass('active');
  $('#view-demons').prop('hidden', name !== 'demons');
  $('#view-fusions').prop('hidden', name !== 'fusions');
  $('#view-parties').prop('hidden', name !== 'parties');

  if (name === 'fusions' && window.Fusions) {
    window.Fusions.carregarLista();
  }

  if (name === 'parties' && window.Parties) {
    window.Parties.load();
  }
}

/* ==================== catálogo (topo) ==================== */

async function loadStats() {
  try {
    const { data } = await api('GET', { rota: 'health' });
    $('#stat-demons').text(data.catalogo.demons);
    $('#stat-races').text(data.catalogo.races);
    $('#stat-skills').text(data.catalogo.skills);
  } catch (e) {
    $('#catalog-stats').addClass('text-danger');
  }
}

/* ==================== módulo: demônios ==================== */

const Demons = {
  state: { page: 1, per_page: 25, sort: 'level', order: 'asc', filters: {} },
  cache: {},

  init() {
    this.loadFilters();
    this.load();

    let timer = null;
    const debounce = (fn) => {
      clearTimeout(timer);
      timer = setTimeout(fn, 280);
    };

    $('#f-q').on('input', () => debounce(() => { Demons.state.page = 1; Demons.load(); }));
    $('#f-race, #f-species, #f-alignment').on('change', () => { this.state.page = 1; this.load(); });
    $('#f-level-min, #f-level-max').on('input', () => debounce(() => { this.state.page = 1; Demons.load(); }));

    $('#f-clear').on('click', () => {
      $('#f-q, #f-level-min, #f-level-max').val('');
      $('#f-race, #f-species, #f-alignment').val('');
      this.state.page = 1;
      this.load();
    });

    $(document).on('click', '#demons-body tr[data-slug]', function () {
      Demons.open($(this).data('slug'));
    });

    $(document).on('click', '[data-fusion]', function () {
      const slug = $(this).data('slug');
      const modo = $(this).data('fusion');
      bootstrap.Modal.getInstance(document.getElementById('modal-demon')).hide();
      if (window.Fusions) window.Fusions.abrir(slug, modo);
    });

    $(document).on('click', '.sortable', function () {
      const campo = $(this).data('sort');
      const st = Demons.state;
      if (st.sort === campo) {
        st.order = st.order === 'asc' ? 'desc' : 'asc';
      } else {
        st.sort = campo;
        st.order = 'asc';
      }
      Demons.load();
    });
  },

  currentFilters() {
    return {
      q: $('#f-q').val().trim() || null,
      race: $('#f-race').val() || null,
      species: $('#f-species').val() || null,
      alignment: $('#f-alignment').val() || null,
      level_min: $('#f-level-min').val() || null,
      level_max: $('#f-level-max').val() || null,
    };
  },

  async loadFilters() {
    try {
      const [racas, grupos] = await Promise.all([
        api('GET', { rota: 'races' }),
        api('GET', { rota: 'species' }),
      ]);
      racas.data.forEach((r) => {
        $('#f-race').append($('<option>').val(r.name).text(r.name + ' (' + r.demon_count + ')'));
      });
      grupos.data.forEach((s) => {
        $('#f-species').append($('<option>').val(s.name).text(s.name));
      });
    } catch (e) {
      toast('Não foi possível carregar os filtros: ' + erroLegivel(e), 'error');
    }
  },

  async load() {
    const params = Object.assign(
      { rota: 'demons', page: this.state.page, per_page: this.state.per_page, sort: this.state.sort, order: this.state.order },
      Object.fromEntries(Object.entries(this.currentFilters()).filter(([, v]) => v !== null && v !== ''))
    );

    $('#demons-body').html('<tr><td colspan="8" class="text-center text-secondary py-4">Carregando…</td></tr>');

    try {
      const { data, meta } = await api('GET', params);
      this.render(data, meta);
      this.renderPagination(meta);
      $('#demons-info').text(meta.total + ' demônio(s) · página ' + meta.page + ' de ' + meta.pages);
    } catch (e) {
      $('#demons-body').html('<tr><td colspan="8" class="text-center text-danger py-4">' + esc(erroLegivel(e)) + '</td></tr>');
    }
  },

  render(rows, meta) {
    if (!rows.length) {
      $('#demons-body').html('<tr><td colspan="8" class="text-center text-secondary py-4">Nenhum demônio com esses filtros.</td></tr>');
      return;
    }

    const html = rows.map((d) => {
      const al = (d.alignment || '').toLowerCase();
      return (
        '<tr data-slug="' + esc(d.slug) + '">' +
        '<td class="fw-semibold">' + esc(d.name) + '</td>' +
        '<td><span class="race-pill">' + esc(d.race) + '</span></td>' +
        '<td class="d-none d-md-table-cell small text-secondary">' + esc(d.species) + '</td>' +
        '<td class="text-end level-cell">' + fmtLevel(d.level) + '</td>' +
        '<td class="text-end d-none d-sm-table-cell">' + esc(d.hp) + '</td>' +
        '<td class="text-end d-none d-sm-table-cell">' + esc(d.mp) + '</td>' +
        '<td class="d-none d-lg-table-cell al-' + esc(al) + '">' + esc(d.alignment || '—') + '</td>' +
        '<td class="d-none d-lg-table-cell small text-secondary">' + esc(d.drop_item || '—') + '</td>' +
        '</tr>'
      );
    }).join('');

    $('#demons-body').html(html);
    this.markSort();
  },

  markSort() {
    $('.sortable').each(function () {
      const campo = $(this).data('sort');
      $(this).find('.arrow').remove();
      if (campo === Demons.state.sort) {
        $(this).append('<span class="arrow ' + (Demons.state.order === 'asc' ? 'asc' : 'desc') + '"></span>');
      }
    });
  },

  renderPagination(meta) {
    const $nav = $('#demons-pagination').empty();
    if (meta.pages <= 1) return;

    const btn = (label, page, disabled, active) => {
      const $b = $('<button class="btn btn-sm"></button>')
        .addClass(active ? 'btn-danger' : 'btn-outline-secondary')
        .text(label)
        .prop('disabled', !!disabled);
      if (!disabled && !active) $b.on('click', () => { Demons.state.page = page; Demons.load(); });
      return $b;
    };

    $nav.append(btn('«', meta.page - 1, meta.page === 1, false));

    const inicio = Math.max(1, meta.page - 2);
    const fim = Math.min(meta.pages, inicio + 4);

    for (let p = inicio; p <= fim; p++) {
      $nav.append(btn(p, p, false, p === meta.page));
    }

    $nav.append(btn('»', meta.page + 1, meta.page === meta.pages, false));
  },

  async open(slug) {
    $('#demon-detail').html('<div class="modal-body py-5 text-center text-secondary">Carregando…</div>');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('modal-demon')).show();

    try {
      const { data } = await api('GET', { rota: 'demon', slug: slug });
      $('#demon-detail').html(this.sheet(data));
    } catch (e) {
      $('#demon-detail').html(
        '<div class="modal-body py-5 text-center text-danger">' + esc(erroLegivel(e)) + '</div>'
      );
    }
  },

  sheet(d) {
    const stat = (k, v) => '<div class="stat"><span class="k">' + k + '</span><span class="v">' + esc(v) + '</span></div>';

    const stats =
      stat('HP', d.hp) + stat('MP', d.mp) + stat('CP', d.cp) +
      stat('St', d.strength) + stat('In', d.intelligence) + stat('Ma', d.magic) +
      stat('Vi', d.vitality) + stat('Ag', d.agility) + stat('Lu', d.luck);

    const atks =
      stat('NAtk', d.natk) + stat('NMin', d.nmin) + stat('NMax', fmtLevel(d.nmax)) +
      stat('PAtk', d.patk) + stat('PHit', d.phit) + stat('Def', d.defense) +
      stat('Eva', d.evasion) + stat('MAtk', d.matk) + stat('MHit', d.mhit);

    const rotulo = { drain: 'drena', repel: 'reflete', null: 'anula', neutral: '—', resist: 'resist', weak: 'fraco' };

    const resists = d.resistances.map((r) => {
      const valor = (r.effect === 'resist' || r.effect === 'weak')
        ? Number(r.damage_pct).toString().replace('.', ',') + '%'
        : rotulo[r.effect] || r.effect;
      return (
        '<div class="resist resist-' + esc(r.effect) + '">' +
        '<span class="el">' + esc(r.element) + '</span>' +
        '<span class="ef">' + esc(valor) + '</span></div>'
      );
    }).join('');

    const skills = d.skills.length
      ? d.skills.map((s) => (
          '<tr><td>' + esc(s.skill) + '</td>' +
          '<td class="text-secondary">' + esc(s.element) + '</td>' +
          '<td class="text-end">' + esc(s.power === null ? '—' : s.power) + '</td>' +
          '<td class="text-end">' + esc(s.cost_mp) + '</td>' +
          '<td class="small text-secondary">' + esc([s.target, s.effect].filter(Boolean).join(' · ')) + '</td></tr>'
        )).join('')
      : '<tr><td colspan="5" class="text-secondary">Nenhuma skill inata.</td></tr>';

    return (
      '<div class="modal-header align-items-start">' +
        '<div class="sheet-title">' +
          '<h3>' + esc(d.name) + '</h3>' +
          '<span class="race-pill">' + esc(d.race) + '</span>' +
          '<span class="badge badge-soft">Nv ' + fmtLevel(d.level) + '</span>' +
          '<span class="badge badge-soft ' + ('al-' + (d.alignment || '').toLowerCase()) + '">' + esc(d.alignment || '—') + '</span>' +
          (d.drop_item ? '<span class="badge badge-soft">drop: ' + esc(d.drop_item) + '</span>' : '') +
        '</div>' +
        '<button type="button" class="btn-close ms-auto" data-bs-dismiss="modal" aria-label="Fechar"></button>' +
      '</div>' +
      '<div class="modal-body">' +
        '<div class="row g-3">' +
          '<div class="col-12 col-lg-6"><h6 class="text-uppercase text-secondary small">Atributos</h6><div class="stat-grid">' + stats + '</div></div>' +
          '<div class="col-12 col-lg-6"><h6 class="text-uppercase text-secondary small">Combate</h6><div class="stat-grid">' + atks + '</div></div>' +
        '</div>' +
        '<h6 class="text-uppercase text-secondary small mt-4">Resistências</h6>' +
        '<div class="resist-grid">' + resists + '</div>' +
        '<div class="d-flex flex-wrap gap-2 mt-4">' +
          '<button class="btn btn-sm btn-danger" data-fusion="reverse" data-slug="' + esc(d.slug) + '">Como obter</button>' +
          '<button class="btn btn-sm btn-outline-light" data-fusion="forward" data-slug="' + esc(d.slug) + '">O que sai dele</button>' +
        '</div>' +
        '<h6 class="text-uppercase text-secondary small mt-4">Skills inatas</h6>' +
        '<div class="table-responsive"><table class="table table-sm demons-table">' +
          '<thead><tr><th>Skill</th><th>Elemento</th><th class="text-end">Poder</th><th class="text-end">MP</th><th>Alvo / efeito</th></tr></thead>' +
          '<tbody>' + skills + '</tbody></table></div>' +
      '</div>'
    );
  },
};

/* ==================== boot ==================== */

$(function () {
  $('#main-tabs').on('click', '.nav-link', function () {
    showView($(this).data('view'));
  });

  loadStats();
  Demons.init();

  if (window.Fusions) {
    window.Fusions.init();
  }

  if (window.Parties) {
    window.Parties.init();
  }
});
