const http = require('node:http');
const net = require('node:net');
const fs = require('node:fs');
const path = require('node:path');
const zlib = require('node:zlib');
const { EventEmitter } = require('node:events');

const RELEASE = /^[a-f0-9]{40}$/;
function unpinUrl(value) {
    const index=value.indexOf('?');if(index<0)return value;
    const query=value.slice(index+1).split('&').filter(pair=>{try{return decodeURIComponent(pair.split('=')[0])!=='__campus_release'}catch{return true}}).join('&');
    return value.slice(0,index)+(query?'?'+query:'');
}
function pinHtml(html, id, host) {
    const script = `<script src="/_campus/client.js?release=${id}" data-release="${id}"></script>`;
    html = html.replace(/<(script|link|iframe)\b[^>]*>/gi, tag => tag.replace(/\b(src|href)=(['"])(.*?)\2/i, (attribute, key, quote, value) => {
        try {
            const url = new URL(value.replaceAll('&amp;', '&'), 'http://'+host);
            if (url.host !== host || !/^https?:$/.test(url.protocol)) return attribute;
            const pinned=url.pathname+url.search+(url.search?'&':'?')+'__campus_release='+id+url.hash;
            return key+'='+quote+pinned.replaceAll('&', '&amp;')+quote;
        } catch { return attribute; }
    }));
    return /<head\b[^>]*>/i.test(html) ? html.replace(/<head\b[^>]*>/i, match => match+script) : script+html;
}

/** This listener survives every application switch. Requests are never replayed. */
class CampusGateway extends EventEmitter {
    constructor({clientFile = path.join(__dirname, 'campus-client.js'), leaseMs = 600000} = {}) {
        super(); Object.assign(this, {clientFile, leaseMs});
        this.backends = new Map();
        this.connections = new Set();
        this.server = http.createServer((req, res) => this.handle(req, res));
        this.server.on('upgrade', (req, socket, head) => this.upgrade(req, socket, head));
        this.server.keepAliveTimeout = 65000; this.server.headersTimeout = 66000;
    }
    register(backend) {
        if (!RELEASE.test(backend.id)) throw new Error('Invalid release identity.');
        this.backends.set(backend.id, {...backend, inflight:0, sockets:0, leasedUntil:0, registeredAt:Date.now()});
        return this.backends.get(backend.id);
    }
    activate(id) {
        if (!this.backends.has(id)) throw new Error('The candidate backend is unavailable.');
        if (this.active && this.active.id!==id) this.active.retiredAt=Date.now();
        this.previous = this.active; this.active = this.backends.get(id);
        this.active.retiredAt=null;
        this.active.activatedAt = Date.now(); this.emit('activated', id);
    }
    inUse(backend) { return backend === this.active || backend.inflight > 0 || backend.sockets > 0 || backend.leasedUntil > Date.now(); }
    listen(port, host='0.0.0.0') {
        return new Promise((resolve, reject) => { this.server.once('error', reject); this.server.listen({port, host, backlog:4096}, resolve); });
    }
    select(req) {
        const url = new URL(req.url, 'http://localhost');
        const pin = req.headers['x-campus-release'] ?? url.searchParams.get('__campus_release');
        const documentNavigation = req.method === 'GET' && req.headers['sec-fetch-dest'] === 'document';
        if (!documentNavigation && RELEASE.test(pin ?? '') && this.backends.has(pin)) return this.backends.get(pin);
        // Previously rendered hashed assets remain available after the pointer moves.
        if (req.method === 'GET' && /^\/build\/assets\//.test(url.pathname)) {
            let relative; try { relative=decodeURIComponent(url.pathname); } catch { return this.active; }
            const candidates = [this.active, ...this.backends.values()].filter(Boolean);
            for (const candidate of candidates) {
                const base = path.join(candidate.project, 'public'); const file = path.join(base, relative);
                if (file.startsWith(base+path.sep) && fs.existsSync(file)) return candidate;
            }
        }
        return this.active;
    }
    handle(req, res) {
        if (req.url.split('?')[0] === '/_campus/client.js') {
            res.writeHead(200, {'Content-Type':'text/javascript; charset=utf-8', 'Cache-Control':'no-cache', 'X-Content-Type-Options':'nosniff'});
            fs.createReadStream(this.clientFile).pipe(res); return;
        }
        if (/^\/_campus\/lease\/[a-f0-9]{40}$/.test(req.url) && req.method === 'POST') {
            const backend = this.backends.get(req.url.split('/').pop());
            if (backend) backend.leasedUntil = Date.now()+this.leaseMs;
            res.writeHead(204, {'Cache-Control':'no-store'}); res.end(); req.resume(); return;
        }
        const backend = this.select(req);
        if (!backend) { res.writeHead(503, {'Retry-After':'2'}); res.end('The campus server is starting.'); return; }
        backend.inflight++;
        let finished = false;
        const finish = () => { if (!finished) { finished=true; backend.inflight--; } };
        res.on('close', finish); res.on('finish', finish);
        const headers = {...req.headers, 'x-forwarded-for':(req.socket.remoteAddress ?? '').replace(/^::ffff:/,''),
            'x-forwarded-proto':'http', 'x-forwarded-host':req.headers.host ?? ''};
        const upstream = http.request({hostname:'127.0.0.1', port:backend.port, method:req.method, path:unpinUrl(req.url), headers}, response => {
            const output = {...response.headers, 'x-campus-release':backend.id};
            delete output.connection; delete output['keep-alive']; delete output['transfer-encoding'];
            if (response.statusCode >= 500) this.emit('backend-error', backend.id);
            if (req.method !== 'HEAD' && /text\/html/i.test(output['content-type'] ?? '') && response.statusCode !== 304) {
                const chunks=[]; let bytes=0; let streaming=false;
                response.on('data', chunk => {
                    if (streaming) { res.write(chunk); return; }
                    chunks.push(chunk); bytes+=chunk.length;
                    if (bytes>8*1024*1024) { streaming=true; res.writeHead(response.statusCode, output); chunks.forEach(c=>res.write(c)); chunks.length=0; }
                });
                response.on('end', () => {
                    if (streaming) { res.end(); return; }
                    try {
                        let body=Buffer.concat(chunks);
                        const encoding=output['content-encoding'];
                        if (encoding==='gzip') body=zlib.gunzipSync(body);
                        else if (encoding==='br') body=zlib.brotliDecompressSync(body);
                        else if (encoding==='deflate') body=zlib.inflateSync(body);
                        else if (encoding && encoding!=='identity') throw new Error('Unknown encoding.');
                        body=Buffer.from(pinHtml(body.toString('utf8'), backend.id, req.headers.host ?? 'localhost'));
                        delete output['content-encoding']; delete output.etag; output['content-length']=body.length;
                        backend.leasedUntil=Date.now()+this.leaseMs;
                        res.writeHead(response.statusCode, output); res.end(body);
                    } catch { res.writeHead(response.statusCode, output); res.end(Buffer.concat(chunks)); }
                });
                response.on('error', ()=>res.destroy());
            } else { res.writeHead(response.statusCode, output); response.pipe(res); }
        });
        upstream.on('error', () => {
            this.emit('backend-error', backend.id);
            if (!res.headersSent) res.writeHead(502, {'Content-Type':'text/plain', 'Cache-Control':'no-store'});
            res.end('The campus service could not complete this request. Your saved records are retained.');
        });
        req.on('aborted', ()=>upstream.destroy()); req.pipe(upstream);
    }
    upgrade(req, socket, head) {
        if (!req.url.startsWith('/app/') || !this.active) { socket.destroy(); return; }
        const backend=this.active;if(!this.websocketPort)backend.sockets++;
        this.connections.add(socket);
        const upstream=net.connect(this.websocketPort??backend.port, '127.0.0.1', ()=> {
            upstream.write(`${req.method} ${req.url} HTTP/${req.httpVersion}\r\n${Object.entries(req.headers).map(([k,v])=>`${k}: ${v}`).join('\r\n')}\r\n\r\n`);
            if (head.length) upstream.write(head); socket.pipe(upstream).pipe(socket);
        });
        upstream.on('error', ()=>socket.destroy()); socket.on('error', ()=>upstream.destroy());
        socket.on('close', ()=>{if(!this.websocketPort)backend.sockets--;this.connections.delete(socket);upstream.destroy();});
    }
}
module.exports = { CampusGateway, pinHtml, unpinUrl, RELEASE };
