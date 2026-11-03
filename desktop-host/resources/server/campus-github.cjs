const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {Readable, Transform} = require('node:stream');
const {pipeline} = require('node:stream/promises');

class GitHubReleases {
    constructor(root, config, fetcher=fetch){
        if(!/^[a-zA-Z0-9_.-]+$/.test(config.owner??'')||!/^[a-zA-Z0-9_.-]+$/.test(config.repository??'')||!config.branch)throw new Error('Invalid GitHub update configuration.');
        Object.assign(this,{root,config,fetcher});this.base=`https://api.github.com/repos/${config.owner}/${config.repository}`;
    }
    token(){
        const file=path.resolve(this.root,this.config.tokenFile??'storage/app/private/campus-github-token.txt');
        const privateRoot=path.resolve(this.root,'storage/app/private')+path.sep;
        if(!file.startsWith(privateRoot))throw new Error('The update token must stay in private campus storage.');
        try{return fs.readFileSync(file,'utf8').trim()}catch{return ''}
    }
    async request(url, extra={}, timeout=20000){
        const token=this.token();let headers={'User-Agent':'BISU-Campus-Updater/1','Accept':'application/vnd.github+json',...extra};
        if(token)headers.Authorization='Bearer '+token;
        for(let n=0;n<6;n++){
            const u=new URL(url);
            if(u.protocol!=='https:'||u.username||u.password||(u.port&&u.port!=='443')
                || !(u.hostname==='api.github.com'||u.hostname==='github.com'||u.hostname.endsWith('.githubusercontent.com')))throw new Error('Untrusted update download location.');
            if(u.hostname!=='api.github.com')delete headers.Authorization;
            const response=await this.fetcher(u.href,{headers,redirect:'manual',signal:AbortSignal.timeout(timeout)});
            if([301,302,303,307,308].includes(response.status)){
                const next=new URL(response.headers.get('location'),u);
                if(next.origin!==u.origin)delete headers.Authorization;
                await response.body?.cancel();url=next.href;continue;
            }
            if(response.status===403||response.status===429){
                const error=new Error('GitHub access or rate limit: the current campus version stays online.');
                const reset=Number(response.headers.get('x-ratelimit-reset'))*1000-Date.now();
                error.retryMs=Math.max(300000,Number(response.headers.get('retry-after'))*1000||reset||900000);throw error;
            }
            return response;
        }
        throw new Error('Too many GitHub download redirects.');
    }
    async head(){
        const response=await this.request(this.base+'/commits/'+encodeURIComponent(this.config.branch),{Accept:'application/vnd.github.sha',...(this.etag?{'If-None-Match':this.etag}:{})});
        if(response.status===304)return null;
        if(response.status!==200)throw new Error('The configured GitHub branch could not be checked (HTTP '+response.status+').');
        this.etag=response.headers.get('etag');const sha=(await response.text()).trim().replace(/^"|"$/g,'');
        if(!/^[a-f0-9]{40}$/.test(sha))throw new Error('GitHub returned an invalid commit identity.');return sha;
    }
    async release(sha){
        const response=await this.request(this.base+'/releases/tags/campus-'+sha);
        if(response.status===404)return null;
        if(response.status!==200)throw new Error('The tested GitHub release is unavailable.');
        const release=await response.json();
        if(release.draft||release.tag_name!=='campus-'+sha||release.target_commitish!==sha)throw new Error('The release identity does not match the branch.');
        const manifest=release.assets?.find(a=>a.name==='campus-update.json');const archive=release.assets?.find(a=>a.name==='campus-code.zip');
        if(!manifest||!archive)return null;
        for(const asset of [manifest,archive])if(!asset.url?.startsWith(this.base+'/releases/assets/'))throw new Error('Untrusted release asset.');
        const raw=await this.request(manifest.url,{Accept:'application/octet-stream'});
        if(raw.status!==200)throw new Error('The release manifest could not be downloaded.');
        const text=await raw.text();if(text.length>100000)throw new Error('Release metadata is too large.');
        const data=JSON.parse(text);
        if(data.sha!==sha||data.protocol!==1||!/^[a-f0-9]{64}$/.test(data.archiveSha256??''))throw new Error('Unsupported or invalid release metadata.');
        return {...data,asset:archive};
    }
    async download(release,destination){
        if(release.asset.size>220000000)throw new Error('The release is too large for automatic installation.');
        const response=await this.request(release.asset.url,{Accept:'application/octet-stream'},180000);
        if(response.status!==200||!response.body)throw new Error('The release download did not finish.');
        let bytes=0;const hash=crypto.createHash('sha256');
        const check=new Transform({transform(chunk,encoding,callback){bytes+=chunk.length;if(bytes>220000000){callback(new Error('Release download limit exceeded.'));return;}hash.update(chunk);callback(null,chunk);}});
        await pipeline(Readable.fromWeb(response.body),check,fs.createWriteStream(destination,{flags:'w'}));
        if(hash.digest('hex')!==release.archiveSha256)throw new Error('The release download checksum did not match.');
    }
}
module.exports={GitHubReleases};
