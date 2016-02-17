var less = require('./less.js-hydra/index.js');
var restify = require('restify');
var pjson = require('./package.json');
var util = require("util");

devmode = true;

var Redis = require("redis");

redis = Redis.createClient({
    'host':'redis103a-dev',
    'port':6379,
    'prefix':'HydraLESSoid'
})

redis.on("error", function (err) {
    console.log("[redis] " + err);
});

logger = function(msg,type) {
    if (typeof type == "string") {
        msg = "["+type+"] "+msg;
    } else {
        msg = "[LESSoid] "+msg;
    }

    var date = new Date();
    msg = "["+date.toUTCString()+"] "+msg;

    if (devmode) {
        console.log(msg);
    }
}

/**
 * Cache Store that uses redis to share data between nodes, and also keeps it own local records.
 * In theory, since an MD5 of the generated content is used at the key, it should never need to be recached
 * as a recache will clear the key.
 */
function CacheStore(){
    // how long to keep a cache before purging it, in seconds.
    // This will keep old content from attempting to stay cached in memory forever.
    this.cacheLife = 60 * 60 * 24;
    this.store = [];
    this.debug = function(msg) {
        logger(msg,"cache");
    }
    /**
     * Check if key exists
     * @param  {[type]} key [description]
     * @return bool     [description]
     */
    this.existsLocal = function(key) {
        return (typeof this.store[key] != 'undefined') ? true : false;
    };
    /**
     * Get from cache. Try local, and if not local, try redis.
     * @param  key
     * @return mixed|bool - false on failure
     */
    this.get = function(key,callback){
        if (this.existsLocal(key)) {
            this.debug("Object returned from local cache.");
            callback(this.store[key].data);
            return;
        }
        this.debug("Object doesn't exist in local cache.");
        redis.get(key, function (err, data) {
            if (err || data == null) {
                this.debug("Object doesn't exist in redis.");
                callback(false);
            } else {
                this.debug("Object returned from redis and set local.");
                this.set(key,data,false,function(){
                    callback(data);
                });
            }
        }.bind(this));
        return;
    };
    /**
     * set value in local cache and redis
     * @param  string  key  key to store by
     * @param  mixed  data data to store in
     * @return nothing
     */
    this.set = function(key,data,redis,callback){
        redis = redis | true;
        if (typeof callback != 'function') {
            callback = function(){};
        }
        this.debug("Object stored in local cache.");
        this.store[key] = {
            'data': data,
            'time': Math.floor(Date.now() / 1000)
        };
        if (redis) {
            this.debug("Setting object in redis cache");
            redis.set(key,data,callback);
        } else {
            callback();
        }
    };
    /**
     * Purge Local Cache based on cacheLife.
     * Doesn't effect redis, as they are set on creation
     * and handles by redis.
     * @return nothing
     */
    this.purgeExpiredLocal = function() {
        var current = Math.floor(Date.now() / 1000);
        for(x in this.store) {
            var age = current - this.store[x].time;
            if (age > this.cacheLife) {
                delete this.store[x];
                redis.del(x);
                logger('removing item from cache','cache');
            }
        }
    }
};

cache = new CacheStore();

setInterval(function(){
    cache.purgeExpiredLocal();
}, 60 * 60 * 1); // purge expired every hour.


less.logger.addListener({
    debug: function(msg) {logger(msg,'less');},
    info: function(msg) {logger(msg,'less');},
    warn: function(msg) {logger(msg,'less');},
    error: function(msg) {logger(msg,'less');}
});

var server = restify.createServer({
  name: pjson.name,
  version: pjson.version
});

server.use(restify.acceptParser(server.acceptable));
server.use(restify.queryParser());
server.use(restify.bodyParser());


function doRender(lessCode,options,callback) {
    less.render(lessCode, options, function(error, output) {
        logger('Attempting to render...','renderer');
        if (error) {
            logger('Error: '+error.message,'renderer');
            var find = error.extract.join("\n");
            logger("Dropping definition containing error: "+find,'renderer');
            return doRender(
                lessCode.replace(find,""),
                options,
                callback
            );
        } else {
            logger('Sending parsed output','renderer');
            return callback(output);
        }
    });
}

server.post('/parse', function (req, res, next) {
    logger('Received parse request...','renderer');

    var p = JSON.parse(JSON.stringify(req.params)); // I... I dont know. Roll with it k?
    var options = p.options;

    cache.get(p.key, function (cachedReturn){


        if(cachedReturn) {
            res.send(cachedReturn);
            return next();
        }

        if (!p.less) {
            logger('Error: No LESS passed to parse.','renderer');
            res.send(500, new Error('No LESS passed to parse!'));
            return next();
        }

        doRender(p.less,options,function(output){
            res.send(output);
            cache.set(p.key,output,true,function(){
                return next();
            });
        });

    });
});

server.get('/', function (req, res, next){
    var body = '<html>'
             + '<head>'
             + '<title>'+pjson.name+'</title>'
             + '<link href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css" rel="stylesheet">'
             + '</head>'
             + '<body>'
             + '<div class="container">'
             + '<div class="page-header"><h1>'+pjson.name+' Running!</h1></div>'
             + '<p class="lead">So yeah, '+ pjson.name+' is running...' + '</p>'
             + '<p class="small">'+pjson.name+' '+pjson.version+'';
             + '</div>'
             + '</body>'
             + '</html>';

    res.writeHead(200, {
      'Content-Length': Buffer.byteLength(body),
      'Content-Type': 'text/html'
    });
    res.write(body);
    res.end();
    return next();
});

server.listen(8099, function () {
  logger(server.name+' listening at '+server.url);
});