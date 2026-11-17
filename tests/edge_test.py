#!/usr/bin/env python3
"""
Testes de borda e abuso da API (roda contra o servidor local).

    php -S 127.0.0.1:8080 -t .        # na raiz do projeto
    python3 tests/edge_test.py [base_url]

Cobre o que a suíte de contrato (api_test.py) não cobre: injeção de SQL nos
filtros, limites de paginação, corpos malformados, unicode, slots inválidos e a
garantia de que nenhuma rota devolve HTML. Sai com código 0 se tudo passar e
remove as parties que criar.
"""

from __future__ import annotations

import json
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = sys.argv[1] if len(sys.argv) > 1 else "http://127.0.0.1:8080/api/index.php"

ok = 0
falhas: list[str] = []


def check(nome: str, cond: bool, extra: object = "") -> None:
    global ok
    if cond:
        ok += 1
        print(f"  ok   {nome}")
    else:
        falhas.append(nome)
        print(f"  FALHA {nome}  {extra}")


def call(method: str, _body: object = None, **params: object) -> tuple[int, str, str]:
    """Faz a chamada e devolve (status, content-type, corpo)."""
    url = BASE + ("?" + urllib.parse.urlencode(params) if params else "")
    data = None
    headers = {}

    if _body is not None:
        data = json.dumps(_body).encode()
        headers["Content-Type"] = "application/json"

    req = urllib.request.Request(url, data=data, method=method, headers=headers)

    try:
        r = urllib.request.urlopen(req, timeout=10)
        return r.status, r.headers.get("Content-Type", ""), r.read().decode()
    except urllib.error.HTTPError as e:
        return e.code, e.headers.get("Content-Type", ""), e.read().decode()


print("== injeção e filtros ==")

s, _, b = call("GET", rota="demons", q="'; DROP TABLE demons; --")
check("SQLi no q não derruba a tabela", s == 200, s)

s, _, b = call("GET", rota="demons", race="Beast' OR '1'='1")
check("SQLi no race é tratado como texto", s == 200 and json.loads(b)["meta"]["total"] == 0, s)

s, _, b = call("GET", rota="demons", sort="level; DROP TABLE demons")
check("sort inválido não vira SQL", s in (200, 422), s)

s, _, b = call("GET", rota="health")
check("catálogo continua íntegro", json.loads(b)["data"]["catalogo"]["demons"] == 244)

print("== limites de paginação ==")

s, _, b = call("GET", rota="demons", per_page=99999)
check("per_page gigante é limitado a 100", json.loads(b)["meta"]["per_page"] == 100)

s, _, b = call("GET", rota="demons", page=0, per_page=2)
check("page=0 vira 1", s == 200 and json.loads(b)["meta"]["page"] == 1)

s, _, b = call("GET", rota="demons", page=-5, per_page=2)
check("page negativo vira 1", s == 200 and json.loads(b)["meta"]["page"] == 1)

s, _, b = call("GET", rota="demons", level_min=90, level_max=10)
check("level_min > level_max não explode", s in (200, 422), s)

print("== identificadores ==")

s, _, b = call("GET", rota="demon", slug="nao-existe")
check("slug inexistente -> 404", s == 404 and json.loads(b)["error"]["code"] == "nao_encontrado", s)

s, _, b = call("GET", rota="demon")
check("slug ausente -> 422", s == 422, s)

s, _, b = call("GET", rota="fusion_chart", per_page=5)
check("chart ignora parâmetros estranhos", s == 200 and json.loads(b)["meta"]["total"] == 239)

print("== corpo da requisição ==")

req = urllib.request.Request(
    BASE + "?rota=parties",
    data="{nao é json".encode(),
    method="POST",
    headers={"Content-Type": "application/json"},
)
try:
    r = urllib.request.urlopen(req, timeout=10)
    s = r.status
except urllib.error.HTTPError as e:
    s = e.code
check("JSON malformado -> 400/422", s in (400, 422), s)

# form-urlencoded continua aceito (documentado no Request)
req = urllib.request.Request(
    BASE + "?rota=parties",
    data=urllib.parse.urlencode({"name": "via formulario", "protagonist_level": 4}).encode(),
    method="POST",
)
try:
    r = urllib.request.urlopen(req, timeout=10)
    s, b = r.status, r.read().decode()
except urllib.error.HTTPError as e:
    s, b = e.code, e.read().decode()
check("corpo de formulário também é aceito", s == 201, s)
form_id = json.loads(b)["data"]["id"] if s == 201 else None
if form_id:
    call("DELETE", rota="party", id=form_id)

print("== validação de campos ==")

s, _, b = call("POST", {"name": "x" * 41, "protagonist_level": 1}, rota="parties")
check("nome com 41 caracteres -> 422", s == 422, s)

s, _, b = call("POST", {"name": "abc", "protagonist_level": "muito alto"}, rota="parties")
check("nível não numérico -> 422", s == 422, s)

NOME = "Teste ünïcode 日本語 Ω≈ç√"
s, _, b = call(
    "POST",
    {"name": NOME, "protagonist_level": 7, "protagonist_alignment": "Neutral"},
    rota="parties",
)
check("nome unicode + emoji é aceito", s == 201, (s, b[:120]))
pid = json.loads(b)["data"]["id"] if s == 201 else None

if pid:
    s, _, b = call("GET", rota="party", id=pid)
    check("unicode volta idêntico", json.loads(b)["data"]["party"] == NOME, json.loads(b)["data"]["party"])

    s, _, _ = call("POST", {"name": NOME, "protagonist_level": 7}, rota="parties")
    check("nome duplicado -> 422", s == 422, s)

    s, _, _ = call("PUT", {"demons": [{"slot": 1, "demon_id": 99999}]}, rota="party_demons", id=pid)
    check("demônio inexistente -> 422", s == 422, s)

    s, _, _ = call(
        "PUT",
        {"demons": [{"slot": 1, "demon_id": 1}, {"slot": 1, "demon_id": 2}]},
        rota="party_demons",
        id=pid,
    )
    check("slot repetido -> 422", s == 422, s)

    s, _, _ = call("PUT", {"demons": [{"slot": 9, "demon_id": 1}]}, rota="party_demons", id=pid)
    check("slot fora de 1..5 -> 422", s == 422, s)

    s, _, _ = call("DELETE", rota="party", id=pid)
    check("party de teste removida", s == 204 and call("GET", rota="party", id=pid)[0] == 404)

print("== contrato de saída ==")

rotas = [
    "health", "demons", "demons_lista", "demon&slug=cerberus", "races", "species",
    "elements", "skills", "parties", "demon_fusions&slug=cerberus",
    "demon_fusions_out&slug=loki", "fusion_chart", "special_fusions",
]
problemas = []
for rota in rotas:
    resp = urllib.request.urlopen(BASE + "?rota=" + rota, timeout=10)
    corpo = resp.read().decode()
    ctype = resp.headers.get("Content-Type", "")
    if "json" not in ctype or corpo.lstrip().startswith("<"):
        problemas.append((rota, ctype))
check(f"as {len(rotas)} rotas devolvem JSON puro (sem HTML)", not problemas, problemas)

print()
if falhas:
    print(f"{ok} ok, {len(falhas)} falhas: " + ", ".join(falhas))
    raise SystemExit(1)

print(f"Todos os {ok} testes de borda passaram.")
