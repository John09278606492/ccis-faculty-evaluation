const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
const http=require('node:http');
const crypto=require('node:crypto');
const {setTimeout:delay}=require('node:timers/promises');
const {CampusGateway,pinHtml,unpinUrl}=require('../../desktop-host/resources/server/campus-gateway.cjs');
const {CampusUpdater}=require('../../desktop-host/resources/server/campus-updater.cjs');
const {GitHubReleases}=require('../../desktop-host/resources/server/campus-github.cjs');
const A='a'.repeat(40),B='b'.repeat(40);

async function backend(id,project){
    let posts=0;
    const server=http.createServer(async(req,res)=>{
        if(req.method==='POST'){posts++;await delay(90);}
        req.resume();res.writeHead(200,{'Content-Type':'application/json'});res.end(JSON.stringify({id,path:req.url}));
    });
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
    return{id,project,port:server.address().port,env:{},posts:()=>posts,stop:()=>new Promise(resolve=>server.close(resolve))};
}
async function fixture(t){
    const root=fs.mkdtempSync(path.join(os.tmpdir(),'campus-update-'));
    fs.mkdirSync(path.join(root,'storage/app/private'),{recursive:true});fs.mkdirSync(path.join(root,'database'));
    fs.writeFileSync(path.join(root,'.env'),'private original key');fs.writeFileSync(path.join(root,'database/campus.sqlite'),'original student results');
    const gateway=new CampusGateway();const old=await backend(A,root);gateway.register(old);gateway.activate(A);await gateway.listen(0,'127.0.0.1');
    const calls=[];const background={pause:async()=>{calls.push('pause');await delay(40)},resume:b=>calls.push('resume:'+b.id)};
    const metadata={schemaSignature:'schema',applicationKey:'key',evaluationKey:'fingerprint',database:'shared-db',storage:'shared-storage',pendingMigrations:0};
    const provider={head:async()=>B,release:async()=>({sha:B,protocol:1,backwardCompatible:true,schemaSignature:'schema',archiveSha256:'c'.repeat(64)}),download:async()=>{await delay(40)}};
    let next;
    const updater=new CampusUpdater({root,php:'unused',gateway,background,provider,config:{enabled:true},
        startBackend:async({id,project})=>{next=await backend(id,project);return next},
        runCommand:async(php,args)=>{if(args[1]==='extract')fs.mkdirSync(args[3],{recursive:true});return JSON.stringify(metadata)},healthCheck:async()=>true});
    updater.links=async()=>{};
    t.after(async()=>{updater.stop();await new Promise(resolve=>gateway.server.close(resolve));await old.stop();if(next)await next.stop();fs.rmSync(root,{recursive:true,force:true})});
    const url='http://127.0.0.1:'+gateway.server.address().port;
    return{root,gateway,old,background,provider,updater,url,calls,metadata};
}

test('new requests switch while an in-flight answer and old-page submissions complete once on their original version',async t=>{
    const f=await fixture(t);
    const answer=fetch(f.url+'/answer',{method:'POST',body:'rating=5'}).then(r=>r.json());
    await delay(15);const update=f.updater.tick();
    const requests=await Promise.all(Array.from({length:35},async()=>{await delay(Math.random()*130);const r=await fetch(f.url+'/page');return[r.status,(await r.json()).id]}));
    await update;
    assert.equal((await answer).id,A);assert.equal(f.old.posts(),1);assert(requests.every(([status])=>status===200));
    assert.equal((await(await fetch(f.url+'/page')).json()).id,B);
    assert.equal((await(await fetch(f.url+'/answer',{method:'POST',headers:{'X-Campus-Release':A},body:'rating=4'})).json()).id,A);
    assert.equal((await(await fetch(f.url+'/answer?__campus_release='+A,{method:'POST',body:'rating=3'})).json()).id,A);
    assert.equal(f.old.posts(),3);assert.equal(f.updater.state.state,'current');
    assert.equal(fs.readFileSync(path.join(f.root,'.env'),'utf8'),'private original key');
    assert.equal(fs.readFileSync(path.join(f.root,'database/campus.sqlite'),'utf8'),'original student results');
    assert.deepEqual(f.calls,['pause','resume:'+B]);
});

test('schema-changing releases wait without downloading or stopping the active application',async t=>{
    const f=await fixture(t);let downloaded=false;
    f.provider.release=async()=>({sha:B,protocol:1,backwardCompatible:true,schemaSignature:'different'});
    f.provider.download=async()=>{downloaded=true};await f.updater.tick();
    assert.equal(f.gateway.active.id,A);assert.equal(downloaded,false);assert.equal(f.calls.length,0);assert.equal(f.updater.state.state,'needs-maintenance');
});

test('a failing candidate leaves the active pointer and existing records untouched',async t=>{
    const f=await fixture(t);f.updater.healthCheck=async()=>false;await f.updater.tick();
    assert.equal(f.gateway.active.id,A);assert.equal(fs.existsSync(f.updater.pointer),false);
    assert.equal(f.updater.state.failed,B);assert.equal(f.calls.at(-1),'resume:'+A);
    assert.equal((await fetch(f.url+'/page')).status,200);
});

test('an unhealthy activated release rolls back code while preserving the shared database',async t=>{
    const f=await fixture(t);await f.updater.tick();
    f.updater.healthCheck=async port=>port===f.old.port;
    await f.updater.monitor();await f.updater.monitor();
    assert.equal(f.gateway.active.id,A);assert.equal(f.updater.state.state,'rolled-back');
    assert.equal((await(await fetch(f.url+'/answer',{method:'POST',headers:{'X-Campus-Release':B}})).json()).id,A);
    assert.equal(fs.readFileSync(path.join(f.root,'database/campus.sqlite'),'utf8'),'original student results');
});

test('pages and resources are pinned without changing signed query bytes or third-party URLs',()=>{
    const original='/copy?student=anonymous%20one&signature=a%2Bb%2Fc';
    const html=pinHtml('<html><head><script src="/livewire/livewire.js?id=old"></script><link href="https://fonts.example/css"><iframe src="'+original.replaceAll('&','&amp;')+'"></iframe></head></html>',A,'campus.test');
    assert(html.includes('/_campus/client.js?release='+A));assert(html.includes('id=old&amp;__campus_release='+A));
    assert(html.includes('href="https://fonts.example/css"'));assert(!html.includes('https://fonts.example/css?'));
    assert.equal(unpinUrl(original+'&__campus_release='+A),original);
    assert.equal(unpinUrl('/livewire/update?__campus_release='+A),'/livewire/update');
});

test('an unchanged commit can still acquire its later CI package',async t=>{
    const f=await fixture(t);f.provider.release=async()=>null;await f.updater.tick();
    assert.equal(f.updater.state.state,'waiting-for-tests');f.provider.head=async()=>null;
    f.provider.release=async()=>({sha:B,protocol:1,backwardCompatible:true,schemaSignature:'schema',archiveSha256:'c'.repeat(64)});
    await f.updater.tick();assert.equal(f.gateway.active.id,B);
});

test('GitHub redirect downloads never forward the private token to an asset host',async t=>{
    const root=fs.mkdtempSync(path.join(os.tmpdir(),'campus-github-'));t.after(()=>fs.rmSync(root,{recursive:true,force:true}));
    fs.mkdirSync(path.join(root,'storage/app/private'),{recursive:true});fs.writeFileSync(path.join(root,'storage/app/private/campus-github-token.txt'),'fixture-private-token');
    const requests=[];
    const client=new GitHubReleases(root,{owner:'owner',repository:'repo',branch:'main'},async(url,options)=>{
        requests.push({url,headers:{...options.headers}});
        return requests.length===1?new Response(null,{status:302,headers:{location:'https://release-assets.githubusercontent.com/asset'}}):new Response('archive');
    });
    await client.request('https://api.github.com/repos/owner/repo/releases/assets/1');
    assert.equal(requests[0].headers.Authorization,'Bearer fixture-private-token');assert.equal(requests[1].headers.Authorization,undefined);
});

test('GitHub rate limits back off and untrusted redirect hosts are rejected',async()=>{
    const cfg={owner:'owner',repository:'repo',branch:'main'};
    const limited=new GitHubReleases(os.tmpdir(),cfg,async()=>new Response(null,{status:429,headers:{'retry-after':'600'}}));
    await assert.rejects(limited.head(),e=>e.retryMs>=600000);
    const redirect=new GitHubReleases(os.tmpdir(),cfg,async()=>new Response(null,{status:302,headers:{location:'https://untrusted.example/file'}}));
    await assert.rejects(redirect.head(),/Untrusted/);
});

test('download corruption is rejected before release extraction',async t=>{
    const root=fs.mkdtempSync(path.join(os.tmpdir(),'campus-corrupt-'));t.after(()=>fs.rmSync(root,{recursive:true,force:true}));
    const client=new GitHubReleases(root,{owner:'owner',repository:'repo',branch:'main'},async()=>new Response('corrupt archive'));
    await assert.rejects(client.download({asset:{url:'https://api.github.com/repos/owner/repo/releases/assets/1',size:15},archiveSha256:crypto.createHash('sha256').update('correct archive').digest('hex')},path.join(root,'download.zip')),/checksum/);
});
