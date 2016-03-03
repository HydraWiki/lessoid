# LESSoid
A LESS parser running as a Node.js RESTful API and implements the official Less.js package.  It is intended as a replacement for the oyejorge/less.php package and works as a drop in replacement for MediaWiki.  It can be used for other projects.

## Why LESSoid?
In the Hydra Wiki Farm, the default LESS.php parser doesn't play well with caching across shared file systems such as gluster and relies on slow file caching techniques.  This Node.js solution uses an in-memory cache and can share the cache to multiple web nodes using Redis.

As for the name, we figured it matched MediaWiki's naming conventions.  Parsoid, Mathoid... Why not LESSoid?

## How does it work?
Magic.

But for real, in this repo (services/lessoid) you will find a lessoid.js file. This will serve up a RESTful api (much like Parsoid). Do to current limitations, it must run on the same machine as the MediaWiki installs, as it must be able to load additional less files. _this may be fixed in the future_.

Also, don't move the services folder from here, as the code will fallback to parsing less over the command line if the Lessoid service is not running. It does this through the less.js-hydra (less.js, modified for our uses) library, so if its moved.. no fallback processing and everything blows up.

## Requirements
* PHP 5.4 minimum, PHP 5.6 or higher recommend.
* Node.js 4.x or higher, may work on earlier versions, but is untested on them.

## How to use
Installation through composor:

	composer require hydrawiki/lessoid

Without composer, download the latest release from the GitHub project and place it in an appropriate place in the project.  [https://github.com/HydraWiki/lessoid/releases]

## Current Development Status
This is alpha level code and the first target is to work seamlessly with MediaWiki.

It is currently a really dirty shim around the default Less_Parser class from less.php designed to trick MediaWiki into using it instead of less.php.

## Other Notes
We are currently using a modified version off the Less.js node package. In the future we can hopefully use the official and have it upgradeable.
