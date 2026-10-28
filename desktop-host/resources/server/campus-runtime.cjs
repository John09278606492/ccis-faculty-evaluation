const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const {spawn, fork} = require('node:child_process');
const {setTimeout:delay} = require('node:timers/promises');

function atomicJson(file, value) {
    fs.mkdirSync(path.dirname(file), {recursive:true}); fs.writeFileSync(file+'.tmp', JSON.stringify(value, null, 2)); fs.renameSync(file+'.tmp', file);
}
function appEnv(root, project, publicPort, backendPort, id) {
    const reverbPort=Number(process.env.CAMPUS_REVERB_PORT??backendPort+7);
    const inherited=Object.fromEntries(Object.entries(process.env).filter(([key])=>/^(PATH|PHPRC|PHP_INI_SCAN_DIR|CCIS_PHP_RUNTIME_DIR|SYSTEMROOT|WINDIR|TEMP|TMP|COMSPEC|PATHEXT|USERPROFILE|APPDATA|LOCALAPPDATA|PROGRAMDATA|PROGRAMFILES|PROGRAMFILES\(X86\)|SYSTEMDRIVE|NUMBER_OF_PROCESSORS|PROCESSOR_ARCHITECTURE)$/i.test(key)));
    const env={...inherited, CAMPUS_SHARED_ROOT:root, CAMPUS_RELEASE_ID:id, DB_DATABASE:path.join(root,'database/campus.sqlite'),
        CAMPUS_REVERB_PORT:String(reverbPort),
        DB_CONNECTION:'sqlite', DB_URL:'',
        APP_CONFIG_CACHE:path.join(root,'storage/framework/cache',`host-${backendPort}-config.php`),
        CLASSROOM_WEBSOCKET_PORT:String(publicPort), REVERB_HOST:'127.0.0.1', REVERB_PORT:String(reverbPort), REVERB_SCHEME:'http',
        REVERB_SERVER_HOST:'127.0.0.1', REVERB_SERVER_PORT:String(reverbPort),
        CLASSROOM_WEBSOCKET_HOST:'', CLASSROOM_WEBSOCKET_SCHEME:'http', APP_DEBUG:'false'};
    // Tokens are read by the update controller, never inherited by application jobs.
    delete env.CAMPUS_GITHUB_TOKEN;
    if (process.env.CAMPUS_PUBLIC_URL) { env.CAMPUS_PUBLIC_URL=process.env.CAMPUS_PUBLIC_URL;env.APP_URL=new URL(process.env.CAMPUS_PUBLIC_URL).origin; }
    return env;
}
function command(php, args, project, env, {timeoutMs=120000, logFile}={}) {
    return new Promise((resolve,reject)=> {
        const fd=logFile ? fs.openSync(logFile,'a') : null;
        const child=spawn(php,['-d','opcache.enable_cli=0',...args],{cwd:project,env,windowsHide:true,stdio:['ignore',fd??'pipe',fd??'pipe']});
        if(fd!==null)fs.closeSync(fd);
        let output=''; if(!logFile){ child.stdout.on('data',b=>{if(output.length<1000000)output+=b});child.stderr.on('data',b=>{if(output.length<1000000)output+=b}); }
        // Staging commands have bounded lifetimes. Live queue/scheduler processes use no timeout.
        const timer=timeoutMs ? setTimeout(()=>{child.kill();reject(new Error('A release preparation command timed out.'));},timeoutMs) : null;
        child.on('error',e=>{clearTimeout(timer);reject(e)});
        child.on('exit',code=>{clearTimeout(timer);if(code===0)resolve(output);else{const error=new Error('A release preparation command failed. See the private update log.');error.diagnostic=output;reject(error);}});
    });
}
async function freeBlock() {
    for(let attempt=0;attempt<100;attempt++){
        const port=20000+Math.floor(Math.random()*1000)*32;const sockets=[];
        try {
            for(let n=0;n<26;n++) await new Promise((resolve,reject)=>{const s=net.createServer();sockets.push(s);s.once('error',reject);s.listen(port+n,'127.0.0.1',resolve)});
            return port;
        }catch{}finally{await Promise.all(sockets.map(s=>new Promise(resolve=>s.listening?s.close(resolve):resolve())));}
    }
    throw new Error('No free private worker ports are available.');
}
async function healthy(port) {
    try {
        for(const route of ['/up','/login']){
            const r=await fetch(`http://127.0.0.1:${port}${route}`,{signal:AbortSignal.timeout(15000),redirect:'manual'});
            await r.arrayBuffer();if(r.status!==200)return false;
        }
        return true;
    }catch{return false;}
}
async function startBackend({root, project, php, publicPort, id}) {
    const port=await freeBlock();const statusFile=path.join(root,'storage/app/campus-updates',`backend-${id}-${port}.json`);
    fs.mkdirSync(path.dirname(statusFile),{recursive:true});
    const env=appEnv(root,project,publicPort,port,id);
    const fd=fs.openSync(path.join(root,'storage/logs',`release-${id}.log`),'a');
    const child=fork(path.join(project,'desktop-host/resources/server/host.cjs'),[project,php,String(port),statusFile,'--campus','--managed-backend',`--public-port=${publicPort}`],
        {env,windowsHide:true,stdio:['ignore',fd,fd,'ipc']});fs.closeSync(fd);
    let spawnError;child.on('error',error=>{spawnError=error});
    const backend={id,project,port,child,env,statusFile};
    backend.stop=async()=>{
        if(child.exitCode!==null)return;
        if(child.connected)child.send('stop');
        for(let i=0;i<180 && child.exitCode===null;i++)await delay(500);
        if(child.exitCode===null)throw new Error('A retired backend has not stopped; its files were retained.');
    };
    try{
        for(let i=0;i<180;i++){
            if(spawnError||child.exitCode!==null)throw new Error('Candidate web workers stopped during startup.');
            let state;try{state=JSON.parse(await fs.promises.readFile(statusFile,'utf8'))}catch{}
            if(state?.state==='error')throw new Error('Candidate web workers failed startup. See the private release log.');
            if(state?.state==='running' && state.health==='healthy'){
                if(!await healthy(port))throw new Error('Candidate web health checks failed.');return backend;
            }
            await delay(500);
        }
        throw new Error('Candidate web workers did not become ready in time.');
    }catch(e){await backend.stop().catch(()=>{});throw e;}
}

/** Only one version owns queues and scheduling. Updates let current work finish. */
class BackgroundRunner {
    constructor({root,php}){Object.assign(this,{root,php});this.children=new Set();this.paused=true;this.lastMinute=-1;}
    resume(backend){this.backend=backend;this.paused=false;this.tick();if(!this.timer)this.timer=setInterval(()=>this.tick(),1000);}
    launch(key,args){
        if(this.paused||[...this.children].some(c=>c.key===key))return;
        const b=this.backend;const log=fs.openSync(path.join(this.root,'storage/logs',`campus-${key}.log`),'a');
        const child=spawn(this.php,['-d','opcache.enable_cli=0',...args],{cwd:b.project,env:b.env,windowsHide:true,stdio:['ignore',log,log]});fs.closeSync(log);
        child.key=key;this.children.add(child);const end=()=>this.children.delete(child);child.on('error',end);child.on('exit',end);
    }
    tick(){
        if(this.paused)return;
        this.launch('queue',['artisan','queue:work','--sleep=1','--tries=1','--max-time=30']);
        this.launch('bulk',['artisan','queue:work','bulk','--queue=bulk','--sleep=1','--tries=1','--timeout=900','--max-time=30']);
        const minute=Math.floor(Date.now()/60000);
        if(minute!==this.lastMinute && ![...this.children].some(c=>c.key==='schedule')){
            this.lastMinute=minute;this.launch('schedule',[path.join(this.backend.project,'scripts/run-campus-schedule.php')]);
        }
    }
    async pause(timeoutMs=950000){
        this.paused=true;
        await command(this.php,['artisan','queue:restart'],this.backend.project,this.backend.env,{logFile:path.join(this.root,'storage/logs/campus-updater.log')});
        const deadline=Date.now()+timeoutMs;
        while(this.children.size){if(Date.now()>deadline)throw new Error('Current background work is still finishing; the running version was kept.');await delay(250);}
    }
    async stop(){await this.pause();clearInterval(this.timer);}
}
module.exports={atomicJson,appEnv,command,freeBlock,healthy,startBackend,BackgroundRunner};
