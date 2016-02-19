# LESSoid
A (very beta) Nodejs based LESS parser replacement for MediaWiki.

## Why LESSoid?
In the Hydra Wiki Farm, the default less parser doesn't play well with caching across multiple wikis and multiple nodes. This nodejs solution uses an in-memory cache, and shares the cache through multiple nodes using redis.

As for the name, we figured it matched. Parsoid, Mathoid.. Why not LESSoid?

## How does it work?
Magic.

But for real, in this repo (services/lessoid) you will find a lessoid.js file. This will serve up a RESTful api (much like Parsoid). Due to current limitations, it must run on the same machine as the MediaWiki installs, as it must be able to load additional less files. _this may be fixed in the future_.

Also, don't move the services folder from here, as the code will fallback to parsing less over the command line if the Lessoid service is not running. It does this through the less.js-hydra (less.js, modified for our uses) library, so if its moved.. no fallback processing and everything blows up.

## How to use
We will get back to you on that– for right now, don't.

## Current status
Probably broken. Seriously, don't use this unless you are comfortable with tinkering. This should is no way be considered ready for live.

It is currently a really dirty shim around the default Less_Parser class from less.php designed to trick MediaWiki into using it instead of less.php.

## Other notes
We are currently using a modified version off the less.js node package. In the future we can hopefully use the official and have it upgradeable.
