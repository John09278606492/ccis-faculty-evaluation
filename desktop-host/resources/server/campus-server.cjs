const fs=require('node:fs');
const path=require('node:path');
const os=require('node:os');
const crypto=require('node:crypto');
const net=require('node:net');
const {spawn}=require('node:child_process');
const {setTimeout:delay}=require('node:timers/promises');
const {CampusGateway,RELEASE}=require('./campus-gateway.cjs');
const {GitHubReleases}=require('./campus-github.cjs');
const {CampusUpdater}=require('./campus-updater.cjs');
const {atomicJson,appEnv,command,startBackend,BackgroundRunner}=require('./campus-runtime.cjs');
const {campusLinks}=require('./network-links.cjs');

class CampusServer {
    constructor(root,php,port){
        Object.assign(this,{root:path.resolve(root),php,port:Number(port)});
        this.statusFile=path.join(this.root,'storage/app/campus-server.json');
        this.stopFile=path.join(this.root,'storage/framework/campus-stop.request');
        this.gateway=new CampusGateway();this.background=new BackgroundRunner({root:this.root,php});
    }
    initial(){
        let id;try{id=JSON.parse(fs.readFileSync(path.join(this.root,'TRANSFER-MANIFEST.json'),'utf8')).revision}catch{}
        if(!RELEASE.test(id??''))id=crypto.createHash('sha1').update(this.root).digest('hex');
        const pointer=path.join(this.root,'storage/app/campus-updates/current.json');
        try{
            const saved=JSON.parse(fs.readFileSync(pointer,'utf8'));
            const project=path.resolve(this.root,saved.project);
            if(RELEASE.test(saved.id) && (project===this.root || project===path.join(this.root,'.campus-releases',saved.id))
                && fs.existsSync(path.join(project,'artisan')))return {id:saved.id,project};
        }catch{}
        return {id,project:this.root};
    }
    status(state,message){
        const active=this.gateway.active;
        let backend={};try{backend=JSON.parse(fs.readFileSync(active?.statusFile,'utf8'))}catch{}
        try { atomicJson(this.statusFile,{state,message,pid:process.pid,port:this.port,backendPort:active?.port,links:campusLinks(os.networkInterfaces(),this.port),
            health:backend.health??'healthy',services:backend.services??[],release:active?.id,
            updates:this.updater?.state?.state??'disabled',updatedAt:new Date().toISOString()}); } catch { console.error('Campus status could not be persisted; the web gateway remains running.'); }
    }
    async optionalSql(initial){
        const env=appEnv(this.root,initial.project,this.port,0,initial.id);
        // This command is read-only and checks credentials before reusing any process.
        const raw=await command(this.php,['artisan','classroom:student-db','host-config'],initial.project,env);
        const settings=JSON.parse(raw.trim().split('\n').pop());
        if(!settings.installed||settings.running)return;
        const log=fs.openSync(path.join(this.root,'storage/logs/campus-student-sql.log'),'a');
        this.sql=spawn(this.php,['artisan','classroom:student-db','serve'],{cwd:initial.project,env,windowsHide:true,stdio:['ignore',log,log]});fs.closeSync(log);
        this.sql.on('error',()=>{});this.sqlEnvironment={project:initial.project,env};
        for(let i=0;i<60;i++){
            if(this.sql.exitCode!==null)throw new Error('The optional student SQL service did not start.');
            try{const check=JSON.parse((await command(this.php,['artisan','classroom:student-db','host-config'],initial.project,env)).trim().split('\n').pop());if(check.running)return;}catch{}
            await delay(500);
        }
        throw new Error('The optional student SQL service did not become ready.');
    }
    async start(){
        if(!Number.isInteger(this.port)||this.port<1024||this.port>65480)throw new Error('Choose a valid campus port.');
        for(const folder of ['storage/logs','storage/framework','storage/framework/cache','storage/framework/sessions','storage/framework/views','storage/app/public'])fs.mkdirSync(path.join(this.root,folder),{recursive:true});
        fs.rmSync(this.stopFile,{force:true});
        const lock=path.join(this.root,'storage/app/campus-updates/controller.lock');fs.mkdirSync(path.dirname(lock),{recursive:true});
        try{
            const previous=JSON.parse(fs.readFileSync(lock,'utf8'));
            try{process.kill(previous.pid,0);throw new Error('Another campus update controller is already running.');}
            catch(e){if(e.code!=='ESRCH')throw e;}
            fs.unlinkSync(lock);
        }catch(e){if(e.code!=='ENOENT')throw e;}
        const lockFd=fs.openSync(lock,'wx');fs.writeFileSync(lockFd,JSON.stringify({pid:process.pid}));fs.closeSync(lockFd);this.lock=lock;
        this.status('starting','Starting the campus gateway and private web workers…');
        const initial=this.initial();await this.optionalSql(initial);
        this.reverbPort=await new Promise((resolve,reject)=>{const s=net.createServer();s.once('error',reject);s.listen(0,'127.0.0.1',()=>{const p=s.address().port;s.close(()=>resolve(p));});});
        process.env.CAMPUS_REVERB_PORT=String(this.reverbPort);this.gateway.websocketPort=this.reverbPort;
        const reverbEnv=appEnv(this.root,initial.project,this.port,0,initial.id);
        await command(this.php,['artisan','classroom:live-keys','--env-file',path.join(this.root,'.env')],initial.project,reverbEnv);
        this.reverbProject=initial.project;this.reverbEnv=reverbEnv;this.launchReverb();
        const factory=options=>startBackend({root:this.root,php:this.php,publicPort:this.port,...options});
        const backend=await factory(initial);this.gateway.register(backend);this.gateway.activate(initial.id);
        await this.gateway.listen(this.port);this.background.resume(this.gateway.active);
        let config={enabled:false};try{config=JSON.parse(fs.readFileSync(path.join(this.root,'campus-updates.json'),'utf8'))}catch{}
        const provider=config.enabled?new GitHubReleases(this.root,config):null;
        this.updater=new CampusUpdater({root:this.root,php:this.php,gateway:this.gateway,background:this.background,provider,startBackend:factory,config});
        this.updater.start();this.status('running','Campus evaluation server ready. Updates are staged without replacing your data.');
        this.watch=setInterval(()=>{
            if(fs.existsSync(this.stopFile))this.stop().catch(e=>this.status('error',e.message));
            else if(!this.stopping)this.status('running','Campus evaluation server ready.');
        },1000);
    }
    launchReverb(){
        if(this.stopping)return;
        const log=fs.openSync(path.join(this.root,'storage/logs/campus-live-updates.log'),'a');
        this.reverb=spawn(this.php,['artisan','reverb:start','--host=127.0.0.1',`--port=${this.reverbPort}`],{cwd:this.reverbProject,env:this.reverbEnv,windowsHide:true,stdio:['ignore',log,log]});fs.closeSync(log);
        let handled=false;const restart=()=>{if(handled)return;handled=true;if(!this.stopping)this.reverbRetry=setTimeout(()=>this.launchReverb(),5000);};
        this.reverb.once('error',restart);this.reverb.once('exit',restart);
    }
    async stop(){
        if(this.stopping)return;this.stopping=true;clearInterval(this.watch);this.updater?.stop();
        this.status('stopping','Finishing current background work before shutdown…');
        while(this.updater?.busy)await delay(250);
        if(this.background.backend)await this.background.stop();
        for(const socket of this.gateway.connections)socket.destroy();
        if(this.gateway.server.listening)await new Promise(resolve=>this.gateway.server.close(resolve));
        for(const backend of this.gateway.backends.values())await backend.stop();
        clearTimeout(this.reverbRetry);if(this.reverb?.exitCode===null)this.reverb.kill();
        if(this.sqlEnvironment){
            const {project,env}=this.sqlEnvironment;
            await command(this.php,['artisan','classroom:student-db','stop'],project,env);
            if(this.sql?.exitCode===null)this.sql.kill();
        }
        if(this.lock)fs.rmSync(this.lock,{force:true});this.status('stopped','Server stopped.');
    }
}
module.exports={CampusServer};
