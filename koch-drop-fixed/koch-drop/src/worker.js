// Koch Drop: серверная часть для Cloudflare Workers (замена api.php).
// Статика (index.html) отдаётся из ./public_html, запросы на /api.php обрабатывает этот Worker.
// Данные игроков и каталог хранятся в Durable Object (SQLite): все запросы идут через один экземпляр,
// поэтому баланс и дроп считаются без гонок, как и с flock() в PHP-версии.
import { DurableObject } from "cloudflare:workers";
import DEFAULTS from "../public_html/data/defaults.json";

class KDErr extends Error {}
const err = (m) => { throw new KDErr(m); };
const I = (x) => { x = Number(x); return Number.isFinite(x) ? Math.trunc(x) : 0; };
const F = (x) => { x = Number(x); return Number.isFinite(x) ? x : 0; };
const rnd = () => { const a = new Uint32Array(1); crypto.getRandomValues(a); return a[0] / 4294967296; };
const randInt = (n) => Math.floor(rnd() * n);
const randHex = (n) => { const a = new Uint8Array(n); crypto.getRandomValues(a); return [...a].map((b) => b.toString(16).padStart(2, "0")).join(""); };
const nf = (x) => { const [i, d] = Number(x).toFixed(2).split("."); return i.replace(/\B(?=(\d{3})+(?!\d))/g, ",") + "." + d; };
const sum = (a) => a.reduce((s, x) => s + x, 0);
const clean = (s, n = 60) => Array.from(String(s ?? "").replace(/[<>"'`\\&]/g, "").trim()).slice(0, n).join("");
const okImg = (s) => { s = String(s ?? "").trim(); return s !== "" && /^https:\/\/[^\s"'<>]+$/.test(s) ? s : ""; };
const has = (v) => v !== undefined && v !== null;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const safeEq = (a, b) => { a = String(a); b = String(b); let d = a.length ^ b.length; for (let i = 0; i < Math.max(a.length, b.length); i++) d |= (a.charCodeAt(i) || 0) ^ (b.charCodeAt(i) || 0); return d === 0; };

/* ---------- подписанная cookie-сессия (замена PHP-сессий) ---------- */
const b64u = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
const hmacKey = (secret) => crypto.subtle.importKey("raw", new TextEncoder().encode(secret), { name: "HMAC", hash: "SHA-256" }, false, ["sign"]);
async function sign(secret, data) { return b64u(await crypto.subtle.sign("HMAC", await hmacKey(secret), new TextEncoder().encode(data))); }
async function packSess(secret, s) { const p = btoa(JSON.stringify(s)); return p + "." + (await sign(secret, p)); }
async function readSess(req, secret) {
  const m = /(?:^|;\s*)kd=([^;]+)/.exec(req.headers.get("Cookie") || "");
  if (!m) return null;
  const [p, sig] = m[1].split(".");
  if (!p || !sig || !safeEq(sig, await sign(secret, p))) return null;
  try { const s = JSON.parse(atob(p)); return s && typeof s.u === "string" ? s : null; } catch { return null; }
}
let SECRET;
async function getSecret(stub) { return SECRET || (SECRET = await stub.secret()); }

const json = (o, extra = {}) => new Response(JSON.stringify(o), { headers: { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store", ...extra } });
const redirect = (loc, cookie) => new Response(null, { status: 302, headers: cookie ? { Location: loc, "Set-Cookie": cookie } : { Location: loc } });
const cookieStr = (val, https) => `kd=${val}; Path=/; Max-Age=2592000; HttpOnly; SameSite=Lax${https ? "; Secure" : ""}`;

/* ---------- Worker: маршрутизация, вход через Steam ---------- */
export default {
  async fetch(req, env) {
    const url = new URL(req.url);
    if (url.pathname === "/api.php") return api(req, env, url);
    const res = await env.ASSETS.fetch(req);
    const h = new Headers(res.headers);
    h.set("X-Content-Type-Options", "nosniff");
    h.set("Referrer-Policy", "strict-origin-when-cross-origin");
    h.set("X-Frame-Options", "SAMEORIGIN");
    return new Response(res.body, { status: res.status, statusText: res.statusText, headers: h });
  },
};

async function api(req, env, url) {
  const a = url.searchParams.get("a") || "";
  const stub = env.GAME.get(env.GAME.idFromName("main"));
  const secret = await getSecret(stub);
  const https = url.protocol === "https:";
  const base = env.BASE_URL ? String(env.BASE_URL).replace(/\/+$/, "") : url.origin;

  if (a === "steam") {
    const q = new URLSearchParams({
      "openid.ns": "http://specs.openid.net/auth/2.0", "openid.mode": "checkid_setup",
      "openid.return_to": base + "/api.php?a=steam_return", "openid.realm": base + "/",
      "openid.identity": "http://specs.openid.net/auth/2.0/identifier_select",
      "openid.claimed_id": "http://specs.openid.net/auth/2.0/identifier_select",
    });
    return redirect("https://steamcommunity.com/openid/login?" + q);
  }

  if (a === "steam_return") {
    const fail = (r) => redirect("/?steam=fail&r=" + r);
    const p = {};
    for (const [k, v] of url.searchParams) p[k] = v;
    if ((p["openid.mode"] || "") !== "id_res") return fail("cancel");
    const m = /^https?:\/\/steamcommunity\.com\/openid\/id\/(\d{17})$/.exec(p["openid.claimed_id"] || "");
    if (!m) return fail("id");
    let rt = null; try { rt = new URL(p["openid.return_to"] || ""); } catch {}
    const nw = (h) => String(h).toLowerCase().replace(/^www\./, "");
    if (!rt || nw(rt.hostname) !== nw(url.hostname) || !rt.pathname.endsWith("api.php")) return fail("host");
    p["openid.mode"] = "check_authentication";
    let res = "";
    try {
      const r = await fetch("https://steamcommunity.com/openid/login", { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded", "User-Agent": "KochDrop/2" }, body: new URLSearchParams(p).toString() });
      res = await r.text();
    } catch { return fail("net"); }
    if (!res) return fail("net");
    if (!/is_valid\s*:\s*true/.test(res)) return fail("verify");
    const sid = m[1]; let name = "Steam " + sid.slice(-6), av = "";
    if (env.STEAM_API_KEY) {
      try {
        const j = await (await fetch("https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v2/?key=" + encodeURIComponent(env.STEAM_API_KEY) + "&steamids=" + sid, { headers: { "User-Agent": "KochDrop/2" } })).json();
        const pl = j?.response?.players?.[0];
        if (pl) { name = pl.personaname || name; av = pl.avatarfull || ""; }
      } catch {}
    }
    await stub.loginSteam(sid, name, av);
    return redirect("/?steam=ok", cookieStr(await packSess(secret, { u: "s" + sid, sid }), https));
  }

  if (req.method !== "POST" || !req.headers.get("X-KD")) return json({ ok: false, err: "bad request" });
  let inp; try { inp = await req.json(); } catch { inp = {}; }
  if (!inp || typeof inp !== "object") inp = {};
  const sess = (await readSess(req, secret)) || { u: "g" + randHex(8) };
  const r = await stub.act(sess, inp);
  return json(r.out, { "Set-Cookie": cookieStr(await packSess(secret, r.sess), https) });
}

/* ---------- Durable Object: игра, баланс, админка ---------- */
export class Game extends DurableObject {
  async secret() {
    let s = await this.ctx.storage.get("secret");
    if (!s) { s = this.env.SESSION_SECRET || randHex(32); await this.ctx.storage.put("secret", s); }
    return s;
  }
  async cat() {
    let c = await this.ctx.storage.get("cfg");
    if (!c) { c = structuredClone(DEFAULTS); await this.ctx.storage.put("cfg", c); }
    return c;
  }
  newU(id, cat) {
    return { id, name: "Гость", avatar: "", steam: 0, balance: I(cat.cfg.start), opens: 0, rare: 0, inv: [], hist: [], uid: 1, st: { spent: 0, sold: 0, rounds: 0, crashBet: 0, crashWin: 0 }, usedP: [], crh: [], luck: 0, upb: 0, cr: null, seen: 0 };
  }
  async withU(id, fn) {
    id = String(id).replace(/\W/g, "");
    const cat = await this.cat(), k = "u:" + id;
    let u = await this.ctx.storage.get(k);
    if (!u) u = this.newU(id, cat);
    u.seen = Math.floor(Date.now() / 1000);
    const r = await fn(u, cat);
    await this.ctx.storage.put(k, u);
    return r;
  }
  async loginSteam(sid, name, av) {
    await this.withU("s" + sid, (u) => { u.steam = 1; u.name = Array.from(String(name)).slice(0, 40).join(""); u.avatar = /^https:\/\//.test(av) ? av : ""; });
  }
  async alog(who, t) {
    const d = (await this.ctx.storage.get("log")) || [];
    d.unshift({ t: Date.now(), who, text: t });
    await this.ctx.storage.put("log", d.slice(0, 200));
  }

  async act(S, inp) {
    try { return { out: await this.run(S, inp), sess: S }; }
    catch (e) {
      if (e instanceof KDErr) return { out: { ok: false, err: e.message }, sess: S };
      console.error(e);
      return { out: { ok: false, err: "Ошибка сервера" }, sess: S };
    }
  }

  async run(S, inp) {
    const env = this.env, st = this.ctx.storage;
    const adminIds = String(env.ADMIN_IDS || "").split(/[,\s]+/).filter(Boolean);
    const isAdm = () => !!S.adm && adminIds.includes(String(S.sid || ""));
    const lg = (u, t, a = 0) => { u.hist.unshift({ t: Date.now(), text: t, amt: a }); u.hist = u.hist.slice(0, 50); };
    const skin = (cat, id) => cat.skins.find((k) => k.id == id) || null;
    const ex = (cat, e) => { const k = skin(cat, e.s); if (!k) return null; const r = cat.rar[k.r]; return { id: e.id, sid: k.id, name: k.name, icon: k.e || "🔫", value: I(k.p), rarity: r.name, color: r.color, img: k.img }; };
    const pub = (cat) => ({ rar: cat.rar, skins: cat.skins, cases: cat.cases.filter((c) => c.on) });
    const stt = (u, cat) => {
      const inv = []; for (const e of u.inv) { const x = ex(cat, e); if (x) inv.push(x); }
      const { edge, minBet, maxBet, topup } = cat.cfg;
      return { balance: u.balance, opens: u.opens, rareDrops: u.rare, inv, hist: u.hist, st: u.st, crh: u.crh, luck: u.luck, upb: u.upb,
        acc: { id: u.id, name: u.name, avatar: u.avatar, steam: u.steam, verified: true }, admin: isAdm(), cat: pub(cat), cfg: { edge, minBet, maxBet, topup } };
    };
    const pickSkin = (cs, cat, luck) => {
      const w = (cs.odds && cs.odds.length ? cs.odds : cat.rar.map((r) => r.w)).slice();
      const m = 1 + Math.max(-100, Math.min(1000, luck)) / 100;
      w.forEach((x, i) => { if (i >= 2) w[i] = x * m; });
      let r = rnd() * sum(w), t = 0;
      for (let i = 0; i < w.length; i++) { r -= w[i]; if (r < 0) { t = i; break; } t = i; }
      const pool = (k) => cs.skins.map((id) => skin(cat, id)).filter((s) => s && s.r == k);
      for (let k = t; k >= 0; k--) { const p = pool(k); if (p.length) return p[randInt(p.length)]; }
      for (let k = t + 1; k < 5; k++) { const p = pool(k); if (p.length) return p[randInt(p.length)]; }
      return err("В кейсе нет скинов");
    };
    const crEval = (u, cash) => {
      const c = u.cr; if (!c) return null;
      const m = Math.exp(0.15 * (Date.now() / 1000 - c.t0)), pt = c.pt, au = c.auto; let mm = pt, won;
      if (au >= 1.01 && au < pt && m >= au) { won = true; mm = au; }
      else if (m >= pt) won = false;
      else if (cash) { won = true; mm = m; }
      else return null;
      u.cr = null; u.st.rounds++; u.crh.unshift(pt); u.crh = u.crh.slice(0, 12); let w = 0;
      if (won) { w = Math.floor(c.bet * mm); u.balance += w; u.st.crashWin += w; lg(u, "Краш: забрано на " + nf(mm) + "×", w - c.bet); }
      else lg(u, "Краш: проигрыш (краш на " + nf(pt) + "×)", -c.bet);
      return { won, win: w, m: Math.round(mm * 100) / 100, pt, bet: c.bet };
    };

    const act = String(inp.a ?? "");
    if (!S.u) S.u = "g" + randHex(8);
    const ST = () => this.withU(S.u, (u, cat) => stt(u, cat));

    switch (act) {
      case "me": return { ok: true, state: await ST() };
      case "logout": S.u = "g" + randHex(8); delete S.sid; delete S.adm; return { ok: true, state: await ST() };
      case "open": {
        const n = Math.max(1, Math.min(5, I(inp.qty ?? 1))), cid = I(inp.case);
        const r = await this.withU(S.u, (u, cat) => {
          const cs = cat.cases.find((c) => c.id == cid && c.on); if (!cs) err("Кейс недоступен");
          const cost = cs.price * n; if (u.balance < cost) err("Недостаточно виртуального баланса"); if (u.inv.length > 1500) err("Инвентарь переполнен — продай предметы");
          u.balance -= cost; u.st.spent += cost; u.opens += n; const d = [];
          for (let i = 0; i < n; i++) { const k = pickSkin(cs, cat, u.luck); const e = { id: u.uid++, s: k.id }; u.inv.push(e); if (k.r >= 3) u.rare++; d.push(ex(cat, e)); }
          lg(u, "Кейс «" + cs.name + "» ×" + n + ": " + d.map((x) => x.name).join(", "), -cost);
          return [d, stt(u, cat)];
        });
        return { ok: true, drops: r[0], state: r[1] };
      }
      case "sell": {
        const r = await this.withU(S.u, (u, cat) => {
          let got = 0; const keep = [], all = !!inp.all;
          for (const e of u.inv) {
            const x = ex(cat, e);
            if (all || e.id == I(inp.id ?? -1)) { got += x ? x.value : 0; if (x) lg(u, "Продано: " + x.name, x.value); } else keep.push(e);
          }
          if (all) lg(u, "Продано предметов: " + (u.inv.length - keep.length), 0);
          u.inv = keep; u.balance += got; u.st.sold += got; return [got, stt(u, cat)];
        });
        return { ok: true, got: r[0], state: r[1] };
      }
      case "upgrade": {
        const r = await this.withU(S.u, (u, cat) => {
          if (u.cr) err("Сначала заверши раунд краша");
          let si = null; u.inv.forEach((e, i) => { if (e.id == I(inp.src ?? -1)) si = i; });
          if (si === null) err("Предмет не найден");
          const s = ex(cat, u.inv[si]), t = skin(cat, I(inp.tgt ?? -1));
          if (!s || !t || t.p <= s.value) err("Неверная цель");
          const base = s.value / t.p * 95; if (base < 1) err("Слишком маленький шанс");
          const ch = Math.min(90, base * (1 + u.upb / 100)), roll = rnd() * 100, win = roll < ch;
          u.inv.splice(si, 1); if (win) u.inv.push({ id: u.uid++, s: t.id });
          lg(u, "Апгрейд " + (win ? "удался" : "не удался") + ": " + s.name + " → " + t.name + " (" + nf(ch) + "%)");
          return [win, roll, stt(u, cat)];
        });
        return { ok: true, win: r[0], roll: r[1], state: r[2] };
      }
      case "topup": {
        const n = Math.floor(F(inp.n ?? 0));
        const s = await this.withU(S.u, (u, cat) => {
          const mx = I(cat.cfg.topupMax); if (!cat.cfg.topup) err("Пополнение отключено");
          if (n < 1 || n > mx) err("Сумма: от 1 до " + mx + " (виртуальных ₽)");
          u.balance += n; lg(u, "Пополнение (демо)", n); return stt(u, cat);
        });
        return { ok: true, state: s };
      }
      case "promo": {
        const code = String(inp.code ?? "").trim().toUpperCase(); if (code === "") err("Введи промокод");
        const r = await this.withU(S.u, async (u) => {
          const cf = await this.cat(), p = cf.promos.find((x) => x.code === code);
          if (!p) err("Промокод не найден");
          if (u.usedP.includes(code)) err("Ты уже использовал этот промокод");
          if (p.max && p.used >= p.max) err("Лимит активаций исчерпан");
          p.used++; await st.put("cfg", cf);
          u.usedP.push(code); u.balance += p.amount; lg(u, "Промокод " + code, p.amount);
          return [p.amount, stt(u, await this.cat())];
        });
        return { ok: true, got: r[0], state: r[1] };
      }
      case "crash_bet": {
        const b = Math.floor(F(inp.bet ?? 0)), au = F(inp.auto ?? 0);
        const s = await this.withU(S.u, (u, cat) => {
          const c = cat.cfg; crEval(u, false);
          if (u.cr) err("Раунд уже идёт");
          if (b < c.minBet || b > c.maxBet) err("Ставка: " + c.minBet + "–" + c.maxBet);
          if (b > u.balance) err("Недостаточно баланса");
          u.balance -= b; u.st.crashBet += b;
          const pt = Math.min(1000, Math.max(1, Math.floor(100 * (1 - c.edge / 100) / (1 - rnd())) / 100));
          u.cr = { bet: b, pt, auto: au >= 1.01 ? au : 0, t0: Date.now() / 1000 };
          return stt(u, cat);
        });
        return { ok: true, state: s };
      }
      case "crash_state": case "crash_cash": {
        const r = await this.withU(S.u, (u, cat) => [crEval(u, act === "crash_cash"), stt(u, cat)]);
        return { ...(r[0] || {}), ok: true, res: r[0], state: r[1] };
      }
      case "adm_login": {
        const pin = String(env.ADMIN_PIN ?? "");
        if (!adminIds.includes(String(S.sid || ""))) err("Нет доступа: войди через Steam с ID админа (ADMIN_IDS в настройках Worker)");
        if (pin === "" || !safeEq(pin, String(inp.pin ?? ""))) { await sleep(1000); err("Неверный PIN"); }
        S.adm = 1; return { ok: true, state: await ST() };
      }
    }

    if (!isAdm()) err("Нет доступа");
    switch (act) {
      case "adm_data": {
        const us = []; for (const [, u] of await st.list({ prefix: "u:" })) if (u) us.push({ id: u.id, name: u.name, steam: u.steam, balance: u.balance, luck: u.luck, upb: u.upb, inv: u.inv.length, opens: u.opens, seen: u.seen });
        us.sort((x, y) => y.seen - x.seen);
        return { ok: true, users: us.slice(0, 300), cat: await this.cat(), logs: ((await st.get("log")) || []).slice(0, 40) };
      }
      case "adm_user": {
        const id = String(inp.id ?? "").replace(/\W/g, "");
        if (!(await st.get("u:" + id))) err("Игрок не найден");
        if (inp.del) { await st.delete("u:" + id); await this.alog(S.sid, "Удалён игрок " + id); return { ok: true }; }
        await this.withU(id, (u, cat) => {
          if (has(inp.balance)) u.balance = Math.max(0, Math.min(1000000000, I(inp.balance)));
          if (has(inp.luck)) u.luck = Math.max(-100, Math.min(1000, F(inp.luck)));
          if (has(inp.upb)) u.upb = Math.max(-100, Math.min(500, F(inp.upb)));
          if (inp.give && skin(cat, I(inp.give))) { u.inv.push({ id: u.uid++, s: I(inp.give) }); lg(u, "Выдан предмет админом: " + skin(cat, I(inp.give)).name); }
        });
        await this.alog(S.sid, "Игрок " + id + ": баланс=" + (inp.balance ?? "-") + " удача=" + (inp.luck ?? "-") + "% апгрейд=" + (inp.upb ?? "-") + "%" + (inp.give ? " выдан скин #" + I(inp.give) : ""));
        return { ok: true };
      }
      case "adm_cat": {
        const n = inp.cat || {}, old = await this.cat(), skins = [], ids = {};
        for (const k of n.skins || []) {
          const id = I(k.id); if (id < 0 || ids[id]) continue; ids[id] = 1;
          skins.push({ id, name: clean(k.name, 80) || "Скин", r: Math.max(0, Math.min(4, I(k.r))), p: Math.max(1, Math.min(1000000000, I(k.p))), e: clean(k.e, 8), img: okImg(k.img) });
        }
        const cases = [], cid = {};
        for (const c of n.cases || []) {
          const id = I(c.id); if (id < 1 || cid[id]) continue; cid[id] = 1; let od = null;
          if (Array.isArray(c.odds) && c.odds.length == 5) { od = c.odds.map((x) => Math.max(0, F(x))); if (sum(od) <= 0) od = null; }
          cases.push({ id, name: clean(c.name) || "Кейс", desc: clean(c.desc, 120), price: Math.max(1, Math.min(1000000000, I(c.price))), icon: clean(c.icon ?? "📦", 8), weapon: clean(c.weapon ?? "🔫", 8),
            glow: /^#[0-9a-f]{6}$/i.test(c.glow || "") ? c.glow : "#c7ff4a", tag: clean(c.tag, 16), real: clean(c.real, 60), img: okImg(c.img), on: !!c.on, odds: od,
            skins: (Array.isArray(c.skins) ? c.skins : []).map(I).filter((x) => ids[x]) });
        }
        const rar = old.rar; rar.forEach((r, i) => { if (has(n.rar?.[i]?.w)) r.w = Math.max(0, F(n.rar[i].w)); });
        if (sum(rar.map((r) => r.w)) <= 0) err("Сумма шансов должна быть > 0");
        const pr = [];
        for (const p of n.promos || []) { const cd = String(p.code ?? "").toUpperCase(); if (/^[A-Z0-9_-]{3,20}$/.test(cd) && I(p.amount) > 0) pr.push({ code: cd, amount: Math.min(10000000, I(p.amount)), max: Math.max(0, I(p.max)), used: Math.max(0, I(p.used)) }); }
        const g = n.cfg || {};
        const cfg = { edge: Math.max(0, Math.min(20, F(g.edge ?? 5))), minBet: Math.max(1, I(g.minBet ?? 10)), maxBet: Math.max(1, I(g.maxBet ?? 100000)), topup: !!g.topup, topupMax: Math.max(1, I(g.topupMax ?? 100000)), start: Math.max(0, I(g.start ?? 2500)) };
        if (!skins.length || !cases.length) err("Нужен хотя бы один скин и один кейс");
        await st.put("cfg", { cfg, rar, skins, cases, promos: pr });
        await this.alog(S.sid, "Каталог обновлён: скинов " + skins.length + ", кейсов " + cases.length);
        return { ok: true, cat: await this.cat(), state: await ST() };
      }
    }
    return err("unknown");
  }
}
