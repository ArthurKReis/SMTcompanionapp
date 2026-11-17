#!/usr/bin/env python3
"""
Teste ponta a ponta da API (roda contra o servidor local).

    php -S 127.0.0.1:8080 -t .        # na raiz do projeto
    python3 tests/api_test.py [base_url]

Sai com código 0 se todos os testes passarem. Limpa as parties criadas.
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


def call(method: str, **params):
    """Faz a requisição e devolve (status, corpo_decodificado)."""
    body = params.pop("json_body", None)
    query = {k: v for k, v in params.items() if v is not None}
    url = BASE + ("?" + urllib.parse.urlencode(query) if query else "")

    data = None
    headers = {}
    if body is not None:
        data = json.dumps(body).encode()
        headers["Content-Type"] = "application/json"

    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=20) as resp:
            raw = resp.read().decode()
            return resp.status, (json.loads(raw) if raw else None)
    except urllib.error.HTTPError as e:
        raw = e.read().decode()
        return e.code, (json.loads(raw) if raw else None)


def check(nome: str, condicao: bool, detalhe: str = "") -> None:
    global ok
    if condicao:
        ok += 1
        print(f"  ok   {nome}")
    else:
        falhas.append(nome)
        print(f"  FAIL {nome} {detalhe}")


print("== catálogo ==")
s, b = call("GET", rota="health")
check("health 200", s == 200)
check("health: 244 demônios", b["data"]["catalogo"]["demons"] == 244, b)
check("health: 13.985 fusões", b["data"]["catalogo"]["fusions"] == 13985)

s, b = call("GET", rota="demons")
check("demons 200 + paginação", s == 200 and b["meta"]["total"] == 244 and len(b["data"]) == 25)
check("ordem por nível", [d["level"] for d in b["data"]] == sorted(d["level"] for d in b["data"]))

s, b = call("GET", rota="demons", q="cerb")
check("busca por nome", s == 200 and len(b["data"]) == 1 and b["data"][0]["name"] == "Cerberus")

s, b = call("GET", rota="demons", race="Beast", level_min=40)
check("filtro raça + nível", s == 200 and all(
    d["race"] == "Beast" and d["level"] >= 40 for d in b["data"]
) and len(b["data"]) > 0)

s, b = call("GET", rota="demons", sort="name", order="desc", per_page=5)
check("sort por nome desc", [d["name"] for d in b["data"]] == sorted(
    [d["name"] for d in b["data"]], reverse=True))

s, b = call("GET", rota="demon", slug="cerberus")
d = b["data"]
check("ficha não expõe mais fusion_kind", "fusion_kind" not in d, list(d)[:8])
check("ficha 200", s == 200 and d["name"] == "Cerberus")
check("ficha: nível 43", d["level"] == 43.0, d["level"])
check("ficha: 3 skills", len(d["skills"]) == 3, len(d["skills"]))
check("ficha: 14 resistências", len(d["resistances"]) == 14, len(d["resistances"]))
fire = next(r for r in d["resistances"] if r["element"] == "fire")
check("Cerberus fire = repel 100", fire["effect"] == "repel" and fire["damage_pct"] == 100.0, fire)
check("resistências em ordem de elemento", [r["element_id"] for r in d["resistances"]] ==
      sorted(r["element_id"] for r in d["resistances"]))

s, b = call("GET", rota="demon", slug="nao-existe")
check("ficha inexistente -> 404", s == 404 and b["error"]["code"] == "nao_encontrado")

for rota, total in (("races", 37), ("species", 13), ("elements", 19), ("skills", 105)):
    s, b = call("GET", rota=rota)
    check(f"{rota} = {total}", s == 200 and len(b["data"]) == total, len(b["data"]) if s == 200 else s)

s, b = call("GET", rota="demons_lista")
check("demons_lista = 244", s == 200 and len(b["data"]) == 244)
ids = {d["name"]: d["id"] for d in b["data"]}
cerb, unic, baize, orth, fla, kin = (ids[n] for n in
    ("Cerberus", "Unicorn", "Bai Ze", "Orthrus", "Flaemis", "Kinnara"))

print("== parties: criação ==")
s, b = call("GET", rota="parties")
iniciais = len(b["data"]) if s == 200 else -1
check("GET parties funciona", s == 200)

payload = {
    "name": "Teste API",
    "protagonist_name": "Kazuya",
    "protagonist_level": 40,
    "protagonist_alignment": "Neutral",
    "comp_upgraded": 0,
    "human_partners": "Heroína",
    "demons": [{"slot": 1, "demon_id": cerb}, {"slot": 2, "demon_id": unic}],
}
s, b = call("POST", rota="parties", json_body=payload)
check("POST parties 201", s == 201, s)
p = b["data"]
pid = p["id"]
check("party criada com 2 demônios", p["demon_count"] == 2 and len(p["demons"]) == 2)
check("COMP limite 3", p["avisos"]["comp"]["limite"] == 3)
check("COMP não excedido", p["avisos"]["comp"]["excedido"] is False)
check("aviso: Cerberus 43 > 40", len(p["avisos"]["acima_do_nivel"]) == 1
      and p["avisos"]["acima_do_nivel"][0]["demon"] == "Cerberus")

s, b = call("GET", rota="parties")
criada = [x for x in b["data"] if x["id"] == pid]
check("party aparece na listagem", s == 200 and len(criada) == 1 and criada[0]["demon_count"] == 2)
check("listagem traz a formação", len(criada[0]["demons"]) == 2)

print("== parties: edição ==")
s, b = call("PATCH", rota="party", id=pid, json_body={"protagonist_level": 50})
check("PATCH nível do protagonista", s == 200 and b["data"]["protagonist_level"] == 50)
check("aviso de nível desaparece", b["data"]["avisos"]["acima_do_nivel"] == [])

s, b = call("PUT", rota="party_demons", id=pid, json_body={"demons": [
    {"slot": 1, "demon_id": cerb}, {"slot": 2, "demon_id": unic},
    {"slot": 3, "demon_id": baize}, {"slot": 4, "demon_id": orth},
]})
check("PUT formação com 4", s == 200 and b["data"]["demon_count"] == 4)
check("4 demônios > COMP 3", b["data"]["avisos"]["comp"]["excedido"] is True)

s, b = call("PATCH", rota="party", id=pid, json_body={"comp_upgraded": 1})
check("COMP atualizado -> limite 4", b["data"]["avisos"]["comp"]["limite"] == 4)
check("COMP atualizado -> sem aviso", b["data"]["avisos"]["comp"]["excedido"] is False)

s, b = call("DELETE", rota="party_demon", id=pid, slot=2)
check("DELETE do slot 2", s == 200 and b["data"]["demon_count"] == 3)
s, b = call("DELETE", rota="party_demon", id=pid, slot=9)
check("DELETE de slot vazio -> 404", s == 404)

print("== parties: validação ==")
s, b = call("POST", rota="parties", json_body={"name": "Teste API"})
check("nome duplicado -> 422", s == 422 and "name" in b["error"]["details"], b)

s, b = call("POST", rota="parties", json_body={"protagonist_name": "X"})
check("sem nome -> 422", s == 422 and "name" in b["error"]["details"], b)

s, b = call("POST", rota="parties", json_body={"name": "Nivel invalido", "protagonist_level": 0})
check("nível 0 -> 422", s == 422 and "protagonist_level" in b["error"]["details"], b)

s, b = call("POST", rota="parties", json_body={"name": "Alinhamento invalido", "protagonist_alignment": "Chaotic"})
check("alinhamento inválido -> 422", s == 422 and "protagonist_alignment" in b["error"]["details"], b)

s, b = call("POST", rota="parties", json_body={"name": "Slots repetidos", "demons": [
    {"slot": 1, "demon_id": cerb}, {"slot": 1, "demon_id": unic}]})
check("slot repetido -> 422", s == 422, b)

s, b = call("POST", rota="parties", json_body={"name": "Demon repetido", "demons": [
    {"slot": 1, "demon_id": cerb}, {"slot": 2, "demon_id": cerb}]})
check("demônio repetido -> 422", s == 422, b)

s, b = call("POST", rota="parties", json_body={"name": "Demon inexistente", "demons": [
    {"slot": 1, "demon_id": 99999}]})
check("demônio inexistente -> 422", s == 422, b)

s, b = call("POST", rota="parties", json_body={"name": "Seis demos", "demons": [
    {"slot": i + 1, "demon_id": i + 1} for i in range(6)]})
check("6 demônios -> 422", s == 422, b)

check("nada foi criado pelos inválidos", len(call("GET", rota="parties")[1]["data"]) == iniciais + 1)

print("== fusões: modo retroativo ==")
s, b = call("GET", rota="demon_fusions", slug="cerberus")
check("Cerberus: 47 combinações", s == 200 and b["meta"]["total"] == 47, b["meta"]["total"] if s == 200 else s)
check("Cerberus: 34 raça / 5 grupo / 8 elemental",
      b["meta"]["por_regra"] == {"normal": 34, "species": 5, "element": 8, "dark": 0}, b["meta"]["por_regra"])
check("Cerberus: obtenção = fusão", b["meta"]["obtencao"] == "fusao", b["meta"]["obtencao"])
nomes = {(f["ingredient_a"]["name"], f["ingredient_b"]["name"]) for f in b["data"]}
check("Cerberus: par Bai Ze x Tam Lin presente", ("Bai Ze", "Tam Lin") in nomes)
check("Cerberus: par Qilin x Senri presente", ("Qilin", "Senri") in nomes)
check("Cerberus: par Chimera x Nightmare presente",
      ("Chimera", "Nightmare") in nomes or ("Nightmare", "Chimera") in nomes)
check("ingredientes trazem raça e nível",
      all("race" in f["ingredient_a"] and "level" in f["ingredient_b"] for f in b["data"]))

s, b = call("GET", rota="demon_fusions", slug="salamander")
check("Salamander: 6 combinações (só elementais)", s == 200 and b["meta"]["total"] == 6, b["meta"]["total"] if s == 200 else s)
check("Salamander: todas as 6 são entre Avatares",
      all(f["ingredient_a"]["race"] == "Avatar" and f["ingredient_b"]["race"] == "Avatar" for f in b["data"]))

s, b = call("GET", rota="demon_fusions", slug="indrajit")
check("Indrajit: 75 combinações", s == 200 and b["meta"]["total"] == 75, b["meta"]["total"] if s == 200 else s)

s, b = call("GET", rota="demon_fusions", slug="loki")
check("Loki: 0 combinações", s == 200 and b["meta"]["total"] == 0)
check("Loki: obtenção = inimigo", b["meta"]["obtencao"] == "inimigo", b["meta"]["obtencao"])

s, b = call("GET", rota="demon_fusions", slug="fanatic")
check("Fanatic (recruta): obtenção = recrutamento", b["meta"]["obtencao"] == "recrutamento", b["meta"]["obtencao"])
check("Fanatic: 1 receita especial", len(b["meta"]["receitas"]) == 1, b["meta"]["receitas"])

s, b = call("GET", rota="demon_fusions", slug="mara")
check("Mara (chefe) = inimigo mesmo sem coluna de tipo", b["meta"]["obtencao"] == "inimigo", b["meta"]["obtencao"])

s, b = call("GET", rota="demon_fusions", slug="shiva")
check("Shiva: obtenção = receita especial", b["meta"]["obtencao"] in ("fusao", "receita_especial"), b["meta"]["obtencao"])

s, b = call("GET", rota="demon_fusions")
check("slug ausente -> 422", s == 422 and "slug" in b["error"]["details"], b)

print("== fusões: modo normal ==")
s, b = call("GET", rota="demon_fusions_out", slug="cerberus")
check("Cerberus: modo normal tem resultados", s == 200 and b["meta"]["total"] > 100, b["meta"]["total"] if s == 200 else s)
check("modo normal: cada linha tem result + other",
      all("result" in f and "other" in f for f in b["data"]))
check("modo normal: o próprio demônio nunca aparece como 'other'",
      all(f["other"]["slug"] != "cerberus" for f in b["data"]))

s, b = call("GET", rota="demon_fusions_out", slug="loki")
check("Loki: 137 saídas", s == 200 and b["meta"]["total"] == 137, b["meta"]["total"] if s == 200 else s)
destinos = {f["result"]["name"] for f in b["data"]}
check("Loki produz Nightmare, Lilim e Lakshmi", {"Nightmare", "Lilim", "Lakshmi"} <= destinos)

print("== chart raça × raça ==")
s, b = call("GET", rota="fusion_chart")
check("chart 200", s == 200)
check("22 raças", b["meta"]["races"] == 22, b["meta"]["races"])
check("239 células", b["meta"]["total"] == 239, b["meta"]["total"])
tipos = {}
for c in b["data"]["cells"]:
    tipos[c["type"]] = tipos.get(c["type"], 0) + 1
check("206 raça / 18 grupo / 15 elemental",
      tipos == {"race": 206, "species": 18, "element_demon": 15}, tipos)
mesma = [c for c in b["data"]["cells"] if c["race_a"] == c["race_b"]]
check("15 células de mesma raça", len(mesma) == 15, len(mesma))
check("Avatar × Avatar = Salamander",
      any(c["race_a"] == "Avatar" and c["result"] == "Salamander" for c in mesma))
check("célula de raça traz o nome do resultado",
      all(c["result"] for c in b["data"]["cells"]))
check("celular de elemental traz slug para abrir a ficha",
      all(c.get("demon_slug") for c in b["data"]["cells"] if c["type"] == "element_demon"))
check("raças ordenadas por grupo",
      [r["species"] for r in b["data"]["races"]][:1] == ["DEITIES"],
      [r["species"] for r in b["data"]["races"]][:3])

print("== receitas especiais ==")
s, b = call("GET", rota="special_fusions")
check("49 receitas no total", s == 200 and len(b["data"]) == 49, len(b["data"]) if s == 200 else s)
s, b = call("GET", rota="special_fusions", kind="trio")
check("9 receitas de trio", s == 200 and len(b["data"]) == 9, len(b["data"]) if s == 200 else s)
check("trio traz 3 ingredientes", all(len(r["ingredients"]) == 3 for r in b["data"]),
      [len(r["ingredients"]) for r in b["data"]])
s, b = call("GET", rota="special_fusions", kind="recruit")
check("10 receitas de recrutamento", s == 200 and len(b["data"]) == 10, len(b["data"]) if s == 200 else s)

print("== roteamento ==")
s, b = call("POST", rota="demons")
check("POST em rota só-GET -> 405", s == 405 and "metodos" in b["error"].get("details", {}), b)

s, b = call("GET", rota="nao_existe")
check("rota inexistente -> 404", s == 404)

s, b = call("GET")
check("raiz devolve o mapa de rotas", s == 200 and len(b["meta"]["rotas"]) >= 15)

print("== limpeza ==")
s, _ = call("DELETE", rota="party", id=pid)
check("DELETE party -> 204", s == 204, s)
s, b = call("GET", rota="party", id=pid)
check("party some depois de excluída", s == 404)
check("lista volta ao estado inicial", len(call("GET", rota="parties")[1]["data"]) == iniciais)

print()
if falhas:
    print(f"FALHARAM {len(falhas)} teste(s):")
    for f in falhas:
        print("  -", f)
    sys.exit(1)

print(f"Todos os {ok} testes passaram.")
