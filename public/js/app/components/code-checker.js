var m=n=>(n.split(".").pop()||"").toLowerCase();function x(n,r){let s=r==="php"?["php","html","htm"]:["html","htm"],t=n.map(e=>e.name).filter(e=>s.includes(m(e))),i=["index.php","index.html","index.htm"].find(e=>t.includes(e));return i?[i,...t.filter(e=>e!==i)]:t}var v=class{constructor(r,s){this.url=r,this.type=s,this.worker=null,this.nextId=0,this.pending=new Map}start(){this.worker||(this.worker=new Worker(this.url,{type:this.type}),this.worker.addEventListener("message",({data:r})=>{this.pending.get(r.id)?.resolve(r),this.pending.delete(r.id)}),this.worker.addEventListener("error",r=>{this.reset(new Error(r.message||"The code runner failed to load."))}))}call(r,s=1e4){this.start();let t=++this.nextId;return new Promise((i,e)=>{let a=setTimeout(()=>{this.reset(new Error(`Stopped after ${s/1e3} seconds. Check for an infinite loop.`))},s);this.pending.set(t,{resolve:o=>{clearTimeout(a),i(o)},reject:o=>{clearTimeout(a),e(o)}}),this.worker.postMessage({...r,id:t})})}reset(r){this.worker?.terminate(),this.worker=null;for(let{reject:s}of this.pending.values())s(r);this.pending.clear()}},w=new Map;function $(n,r="module"){return w.has(n)||w.set(n,new v(n,r)),w.get(n)}async function E(n,r,s){let t=$(`${n}/php-worker.js`),i=t.worker?1e4:1e4*6,e=await t.call({files:r,request:s},i);if(!e.ok)throw new Error(e.error);return{status:e.status,body:e.body,location:e.headers?.location?.[0]??null}}async function S(n,r,s=""){let t=$(`${n}/sql/worker.sql-wasm.js`,"classic"),i=r.filter(o=>m(o.name)==="sql").map(o=>o.content).join(`
;
`);await t.call({action:"open"});let e=await t.call({action:"exec",sql:i});if(!s.trim())return{tables:e.results??[],error:e.error??null};let a=await t.call({action:"exec",sql:s});return{tables:a.results??[],error:a.error??e.error??null}}function g(n,r=""){if(!n||n.startsWith("#")||/^[a-z][a-z0-9+.-]*:/i.test(n)||n.startsWith("//"))return null;let s=new URL(r||"index.html","http://project.invalid/"),t=new URL(n,s);return t.origin==="http://project.invalid"?{path:decodeURIComponent(t.pathname.slice(1)),query:t.search.slice(1)}:null}var y=(n,r)=>g(n,r)?.path;function T(n,r,s,t=null){let i=new Map(r.map(o=>[o.name,o.content])),e={};n=n.replace(/<link\b[^>]*>/gi,o=>{let c=o.match(/\bhref\s*=\s*["']([^"']+)["']/i)?.[1],l=c&&/\brel\s*=\s*["']?stylesheet/i.test(o)?i.get(y(c,t)):void 0;return l===void 0?o:`<style>${l.replace(/<\/style/gi,"</style").replace(/\r?\n/g," ")}</style>`}),n=n.replace(/<script\b([^>]*)\bsrc\s*=\s*["']([^"']+)["']([^>]*)>\s*<\/script>/gi,(o,c,l,p)=>{let d=i.get(y(l,t));if(d===void 0)return o;let h=y(l,t);return e[h]=h,`<script${c}${p}>${d.replace(/<\/script/gi,"<\\/script")}<\/script>`});let a=`<script>${C(s,e,t).replace(/\n\s*/g," ")}<\/script>`;for(let o of[/<head\b[^>]*>/i,/<html\b[^>]*>/i,/<!doctype[^>]*>/i])if(o.test(n))return n.replace(o,c=>c+a);return a+n}function j(n){let r=n.filter(t=>m(t.name)==="css").map(t=>`<link rel="stylesheet" href="${t.name}">`).join(""),s=n.filter(t=>m(t.name)==="js").map(t=>`<script src="${t.name}"><\/script>`).join("");return`<!DOCTYPE html><html><head><meta charset="utf-8">${r}</head><body>${s}</body></html>`}function C(n,r,s){return`(function () {
    var nonce = ${JSON.stringify(n)};
    var scriptNames = ${JSON.stringify(r)};
    var pageName = ${JSON.stringify(s)};
    function send(message) { message.nonce = nonce; parent.postMessage(message, '*'); }
    function format(value) {
        if (typeof value === 'string') return value;
        if (value instanceof Error) return value.name + ': ' + value.message;
        try { return JSON.stringify(value); } catch (e) { return String(value); }
    }
    ['log', 'info', 'warn', 'error', 'debug'].forEach(function (level) {
        var original = console[level];
        console[level] = function () {
            try { send({ type: 'console', level: level, text: Array.prototype.map.call(arguments, format).join(' ') }); } catch (e) {}
            if (original) original.apply(console, arguments);
        };
    });
    window.addEventListener('error', function (event) {
        var file = scriptNames[event.filename] || pageName;
        var where = event.lineno ? ' (' + (file ? file + ', ' : '') + 'line ' + event.lineno + ')' : '';
        send({ type: 'console', level: 'error', text: event.message + where });
    });
    window.addEventListener('unhandledrejection', function (event) {
        send({ type: 'console', level: 'error', text: 'Uncaught (in promise) ' + format(event.reason) });
    });
    window.addEventListener('load', function () { send({ type: 'loaded' }); });
    window.addEventListener('message', function (event) {
        var data = event.data;
        if (event.source !== parent || ! data || data.nonce !== nonce || data.type !== 'check') return;
        var code = String(data.code).replace(/[;\\s]+$/, ''), run;
        try { run = new Function('return (async function () { return (' + code + '\\n); })();'); }
        catch (e) {
            try { run = new Function('return (async function () { ' + code + '\\n })();'); }
            catch (error) { send({ type: 'check-result', error: format(error) }); return; }
        }
        Promise.resolve().then(run).then(function (value) {
            send({ type: 'check-result', value: value === undefined || value === null ? '' : format(value) });
        }, function (error) { send({ type: 'check-result', error: format(error) }); });
    });
    ['localStorage', 'sessionStorage'].forEach(function (name) {
        try { window[name].length; } catch (e) {
            var data = {};
            Object.defineProperty(window, name, { value: {
                getItem: function (key) { return Object.prototype.hasOwnProperty.call(data, key) ? data[key] : null; },
                setItem: function (key, value) { data[key] = String(value); },
                removeItem: function (key) { delete data[key]; },
                clear: function () { data = {}; },
                key: function (index) { return Object.keys(data)[index] || null; },
                get length() { return Object.keys(data).length; }
            } });
        }
    });
    document.addEventListener('click', function (event) {
        var link = event.target.closest && event.target.closest('a[href]');
        if (! link) return;
        var href = link.getAttribute('href');
        if (href.charAt(0) === '#') return;
        event.preventDefault();
        send({ type: 'navigate', href: href });
    }, true);
    document.addEventListener('submit', function (event) {
        var form = event.target;
        event.preventDefault();
        var fields = [];
        new FormData(form, event.submitter).forEach(function (value, key) {
            if (typeof value === 'string') fields.push([key, value]);
        });
        send({ type: 'submit', method: (form.getAttribute('method') || 'GET').toUpperCase(), action: form.getAttribute('action') || '', fields: fields });
    }, true);
})();`}var O=8e3,P=300,q=2e3;function M(n,r=!1){let t=String(n??"").replace(/\r\n?/g,`
`).split(`
`).map(i=>i.replace(/[ \t ]+/g," ").trim()).filter(i=>i!=="").join(`
`);return r?t.toLowerCase():t}function U(n,r,s=!1){let t=M(n,s),i=M(r,s);return t===i||t.replace(/\s+/g,"")===i.replace(/\s+/g,"")}function A(n){let r=String(n).replace(/<(br|hr)\b[^>]*>|<\/(p|div|li|tr|h[1-6]|table|ul|ol|pre|section|article|header|footer|form|label|option|dt|dd|blockquote)>/gi,`$&
`).replace(/<\/(td|th)>/gi,"$& "),s=new DOMParser().parseFromString(r,"text/html");return s.querySelectorAll("script, style, template").forEach(t=>t.remove()),s.body?.textContent??""}function _(n){return n.map(r=>r.values.map(s=>s.map(t=>t===null?"NULL":String(t)).join(" | ")).join(`
`)).join(`

`)}function N(n,r){let s=String(n??"").split(/\r?\n/).map(c=>c.trim()).filter(Boolean),t=x(r,"php")[0]??"index.php",e=(/^\/?[\w./-]*\.(php|html?)(\?.*)?$/i.test(s[0]??"")||(s[0]??"").startsWith("?")?g(s.shift(),t):null)??{path:t,query:""},a=`/${e.path||t}${e.query?`?${e.query}`:""}`;if(!s.length)return{method:"GET",url:a};let o=new URLSearchParams(s.map(c=>{let l=c.indexOf("=");return l===-1?[c,""]:[c.slice(0,l),c.slice(l+1)]})).toString();return{method:"POST",url:a,body:o,headers:{"content-type":"application/x-www-form-urlencoded"}}}async function R(n,r,s){let t=N(s,r);for(let i=0;i<5;i++){let e=await E(n,r,t);if(e.location&&e.status>=300&&e.status<400){let o=g(e.location,t.url.slice(1).split("?")[0]);if(!o)break;t={method:"GET",url:`/${o.path}${o.query?`?${o.query}`:""}`};continue}let a=A(e.body);return e.status===200?a:`HTTP ${e.status}
${a}`}return"Too many redirects, or a redirect outside this project."}async function D(n,r,s){let{tables:t,error:i}=await S(n,r,String(s??"")),e=_(t);return i?`${e}
Error: ${i}`.trim():e}function F(n,r,s){let t=x(r,"web")[0]??null,i=t?r.find(l=>l.name===t).content:j(r),e=Math.random().toString(36).slice(2),a=String(s??"").trim(),o=document.createElement("iframe");o.setAttribute("sandbox","allow-scripts allow-forms"),o.setAttribute("aria-hidden","true"),o.tabIndex=-1,o.style.cssText="position:fixed;left:-12000px;top:0;width:1024px;height:768px;border:0;";let c=[];return new Promise(l=>{let p=!1,d=f=>{p||(p=!0,clearTimeout(h),window.removeEventListener("message",b),o.remove(),l(f))},h=setTimeout(()=>d(`Timed out after ${O/1e3} seconds.`),O),b=f=>{let u=f.data;if(!(f.source!==o.contentWindow||u?.nonce!==e)){if(u.type==="console")c.push(u.level==="error"?`Error: ${u.text}`:String(u.text));else if(u.type==="loaded")setTimeout(()=>o.contentWindow?.postMessage({type:"check",nonce:e,code:a||"document.body ? document.body.innerText : ''"},"*"),P);else if(u.type==="check-result"){let k=u.error!==void 0?`Error: ${u.error}`:String(u.value);d(a?k:[k,...c.length?["Console:",...c]:[]].join(`
`))}}};window.addEventListener("message",b),o.srcdoc=T(i,r,e,t),n.appendChild(o)})}async function L({baseUrl:n,runner:r,files:s,input:t,container:i}){try{let e=r==="php"?await R(n,s,t):r==="sql"?await D(n,s,t):await F(i,s,t);return e.length>q?`${e.slice(0,q)}\u2026`:e}catch(e){return`Error: ${e.message??e}`}}function I({mode:n,runnerBaseUrl:r,questions:s,answers:t,pointsPath:i=null}){return{mode:n,questions:s,state:"idle",rows:[],checked:0,total:t.length,applied:0,failed:0,expected:{},error:"",init(){this.mode==="single"&&this.start()},async referenceOutputs(e){var a;return(a=this.expected)[e]??(a[e]=(async()=>{let o=s[e],c=[];for(let l of o.cases)c.push(await L({baseUrl:r,runner:o.runner,files:o.reference,input:l.input,container:this.$refs.frames}));return c})()),this.expected[e]},async checkAnswer(e){let a=s[e.questionId],o=e.files??await this.$wire.codeCheckFiles(e.id),c=await this.referenceOutputs(e.questionId),l=[];for(let[p,d]of a.cases.entries()){let h=o===null?"No code submitted.":await L({baseUrl:r,runner:a.runner,files:o,input:d.input,container:this.$refs.frames});l.push({label:d.label||`Test ${p+1}`,passed:o!==null&&U(c[p],h,a.ignoreCase),expected:c[p],actual:h})}return l},async start(){if(this.state!=="running"){Object.assign(this,{state:"running",rows:[],checked:0,applied:0,failed:0,error:""});try{for(let e of t){let a=await this.checkAnswer(e),o=await this.$wire.recordAutoCheck(e.id,a,this.mode==="bulk");this.checked++,this.applied+=o.applied?1:0,this.failed+=o.passed<o.total?1:0,this.rows.push({...e,cases:a,...o}),this.mode==="single"&&i&&[null,void 0,""].includes(this.$wire.get(i))&&this.$wire.set(i,o.suggested,!1)}this.state="done"}catch(e){this.error=e.message??String(e),this.state="error"}}},useSuggested(e){this.$wire.set(i,e.suggested,!1)}}}export{I as default,M as normalizeOutput,A as pageText,N as phpRequest,L as projectOutput,U as sameOutput};
