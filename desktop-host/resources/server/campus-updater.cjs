const fs=require('node:fs');
const path=require('node:path');
const {spawn}=require('node:child_process');
const {atomicJson,command,healthy}=require('./campus-runtime.cjs');
const {RELEASE}=require('./campus-gateway.cjs');

class CampusUpdater {
    constructor({root,php,gateway,background,provider,startBackend,config={},runCommand=command,healthCheck=healthy}){
        Object.assign(this,{root,php,gateway,background,provider,startBackend,config,runCommand,healthCheck});
        this.folder=path.join(root,'storage/app/campus-updates');fs.mkdirSync(this.folder,{recursive:true});
        this.stateFile=path.join(this.folder,'update.json');this.pointer=path.join(this.folder,'current.json');
        try{this.state=JSON.parse(fs.readFileSync(this.stateFile,'utf8'))}catch{this.state={}}
        this.state={...this.state,state:config.enabled?'watching':'disabled'};this.save();
        gateway.on('backend-error',id=>{
            if(id!==gateway.active?.id)return;
            this.webErrors=(this.webErrors??[]).filter(time=>Date.now()-time<60000);this.webErrors.push(Date.now());
            if(this.webErrors.length>=3&&Date.now()-(gateway.active.activatedAt??0)<900000)this.forceRollback=true;
        });
    }
    save(values={}){this.state={...this.state,...values,current:this.gateway.active?.id,updatedAt:new Date().toISOString()};atomicJson(this.stateFile,this.state);}
    async inspect(backend){
        const result=await this.runCommand(this.php,[path.join(this.root,'scripts/campus-release.php'),'inspect',backend.project],backend.project,backend.env);
        return JSON.parse(result.trim());
    }
    async links(project){
        if(process.platform==='win32')await new Promise((resolve,reject)=>{
            const p=spawn('powershell.exe',['-NoProfile','-ExecutionPolicy','Bypass','-File',path.join(this.root,'scripts/prepare-campus-release.ps1'),'-Release',project,'-SharedRoot',this.root],{windowsHide:true,stdio:'ignore'});
            p.on('error',reject);p.on('exit',code=>code===0?resolve():reject(new Error('Could not attach the existing private data to the staged release.')));
        });else{
            await fs.promises.mkdir(path.join(project,'bootstrap/cache'),{recursive:true});
            await fs.promises.symlink(path.join(this.root,'storage'),path.join(project,'storage'),'dir');
            await fs.promises.symlink(path.join(this.root,'storage/app/public'),path.join(project,'public/storage'),'dir');
        }
    }
    async tick(){
        if(this.busy||this.monitorBusy||this.stopping||!this.config.enabled)return;
        this.busy=true;let candidate;let paused=false;let attempted;
        try{
            const head=await this.provider.head();const sha=head??this.state.pending;
            attempted=sha;
            this.save({lastChecked:new Date().toISOString()});
            if(!sha||sha===this.gateway.active.id){this.save({state:'current',pending:null,message:'The campus server is up to date.'});return;}
            if(!RELEASE.test(sha))throw new Error('Invalid update identity.');
            if(this.state.failed===sha){this.save({state:'waiting',pending:sha,message:'This release previously failed validation. Waiting for a corrected release.'});return;}
            this.save({state:'waiting-for-tests',pending:sha,message:'Waiting for the tested release package; the current version is still serving users.'});
            const release=await this.provider.release(sha);if(!release)return;
            const old=this.gateway.active;
            const baseline=await this.inspect(old);
            if(release.protocol!==1||release.backwardCompatible!==true||release.schemaSignature!==baseline.schemaSignature||baseline.pendingMigrations){
                this.save({state:'needs-maintenance',message:'This release changes the database or deployment protocol. It was held to keep current evaluations available.'});return;
            }
            if(this.gateway.backends.size>=3){this.save({state:'waiting',message:'Existing evaluation pages still use earlier versions. Waiting for them to finish.'});return;}
            if(fs.statfsSync && fs.statfsSync(this.root).bavail*fs.statfsSync(this.root).bsize<1200000000){this.save({state:'waiting',message:'More free disk space is needed before staging an update.'});return;}
            this.save({state:'staging',message:'Preparing the new version beside the running version.'});
            const releases=path.join(this.root,'.campus-releases');fs.mkdirSync(releases,{recursive:true});
            const project=path.join(releases,sha);const archive=path.join(this.folder,sha+'.zip.part');
            if(fs.existsSync(project))throw new Error('A previous candidate exists and was retained for inspection. Publish a corrected release.');
            await this.provider.download(release,archive);
            if(this.stopping)throw new Error('Update preparation stopped with the server.');
            await this.runCommand(this.php,[path.join(this.root,'scripts/campus-release.php'),'extract',archive,project,sha,release.archiveSha256],old.project,old.env);
            await this.links(project);
            const nextEnv={...old.env,APP_CONFIG_CACHE:path.join(project,'bootstrap/cache/preflight.php')};
            const inspected=await this.inspect({project,env:nextEnv});
            if(this.stopping)throw new Error('Update preparation stopped with the server.');
            for(const key of ['schemaSignature','applicationKey','evaluationKey','database','storage'])if(inspected[key]!==baseline[key])throw new Error('The candidate did not retain the campus data and keys.');
            candidate=await this.startBackend({project,id:sha});
            if(this.stopping)throw new Error('Update preparation stopped with the server.');
            this.save({state:'draining',message:'The new version is ready. Waiting for current background work to finish.'});
            paused=true;await this.background.pause();
            const backups=path.join(this.folder,'backups');fs.mkdirSync(backups,{recursive:true});
            await this.runCommand(this.php,[path.join(this.root,'scripts/campus-release.php'),'snapshot',old.project,path.join(backups,Date.now()+'-'+old.id+'.sqlite')],old.project,old.env);
            if(!await this.healthCheck(candidate.port))throw new Error('The candidate stopped passing health checks before activation.');
            if(this.stopping)throw new Error('Update preparation stopped with the server.');
            atomicJson(this.pointer,{id:sha,project:path.relative(this.root,project)});
            this.gateway.register(candidate);this.gateway.activate(sha);this.background.resume(this.gateway.active);paused=false;
            this.webErrors=[];this.forceRollback=false;this.healthFailures=0;
            this.lastGood=old;
            this.save({state:'current',pending:null,failed:null,lastUpdated:new Date().toISOString(),message:'The tested update is active. Existing pages and records were retained.'});
        }catch(error){
            if(error.diagnostic)fs.appendFileSync(path.join(this.root,'storage/logs/campus-updater.log'),new Date().toISOString()+' '+error.diagnostic+'\n');
            if(candidate&&this.gateway.active?.id!==candidate.id)await candidate.stop().catch(()=>{});
            if(paused&&!this.stopping)this.background.resume(this.gateway.active);
            this.save({state:'retrying',failed:candidate?.id??(attempted&&fs.existsSync(path.join(this.root,'.campus-releases',attempted))?attempted:this.state.failed),message:error.message,retryAfter:error.retryMs?new Date(Date.now()+error.retryMs).toISOString():null});
            this.retryAt=Date.now()+(error.retryMs??300000);
        }finally{this.busy=false;}
    }
    async monitor(){
        if(this.busy||this.monitorBusy||this.stopping||!this.gateway.active)return;
        this.monitorBusy=true;
        try {
        if(this.forceRollback||!await this.healthCheck(this.gateway.active.port)){
            this.healthFailures=(this.healthFailures??0)+1;
            if(this.healthFailures>=2&&this.lastGood&&await this.healthCheck(this.lastGood.port)){
                const failed=this.gateway.active.id;this.gateway.active.disabled=true;this.gateway.activate(this.lastGood.id);
                atomicJson(this.pointer,{id:this.lastGood.id,project:path.relative(this.root,this.lastGood.project)||'.'});
                this.save({state:'rolled-back',failed,pending:failed,message:'The new version stopped passing health checks. The previous code is serving users; the database was retained.'});
                this.busy=true;try{await this.background.pause(950000,this.lastGood);this.background.resume(this.gateway.active);}finally{this.busy=false;}
                this.healthFailures=0;this.forceRollback=false;this.webErrors=[];
            }
        }else this.healthFailures=0;
        for(const [id,backend] of this.gateway.backends){
            if(backend===this.lastGood && Date.now()-(this.gateway.active.activatedAt??Date.now())<900000)continue;
            const aged=backend!==this.gateway.active&&backend.retiredAt&&Date.now()-backend.retiredAt>86400000&&backend.inflight===0;
            if(!this.gateway.inUse(backend)||aged||(backend.disabled&&backend.inflight===0)){
                this.gateway.backends.delete(id);await backend.stop().catch(()=>{});
                if(this.lastGood?.id===id)this.lastGood=null;
            }
        }
        } finally { this.monitorBusy=false; }
    }
    start(){
        const interval=Math.max(300,Number(this.config.intervalSeconds)||300)*1000;
        this.poll=setInterval(()=>{if(Date.now()>(this.retryAt??0))this.tick().catch(()=>{})},interval);
        this.health=setInterval(()=>this.monitor().catch(()=>{}),15000);
        if(this.config.enabled)this.tick().catch(()=>{});
    }
    stop(){this.stopping=true;clearInterval(this.poll);clearInterval(this.health);}
}
module.exports={CampusUpdater};
