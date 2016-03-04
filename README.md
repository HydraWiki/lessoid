# LESSoid
A LESS parser running as a Node.js RESTful API and implements the [official Less.js package][lessmodified].  It is intended as a replacement for the oyejorge/less.php package and works as a drop in replacement for MediaWiki.  It can be used for other projects.

## Why LESSoid?
In the Hydra Wiki Farm, the default LESS.php parser doesn't play well with caching across shared file systems such as gluster and relies on slow file caching techniques.  This Node.js solution uses an in-memory cache and can share the cache to multiple web nodes using Redis.

As for the name, we figured it matched MediaWiki's naming conventions.  Parsoid, Mathoid... Why not LESSoid?

## LESSoid versus LESS.php
* PROS
	* Initial benchmarks show significant performance increases for parsing and high concurrency situations.
		* Once the code enters a beta stage we will release official benchmarks.
	* New Less.js releases can be implemented immediately to get new features and bug fixes.

* CONS
	* Installations on shared hosts that can not run LESSoid as a service will fall back to invoking lessc from the command line.  The lessc fall back can be as slow as less.php in some situations.

## How does it work?
The LESSoid package presents a Less_Parser class that is compatible with the less.php class of the same name.  Existing projects should be able remove the existing less.php package and instead include lessoid package.  If there are compatibility problems [please report the issue](https://github.com/HydraWiki/lessoid/issues).

All file includes, variables, and other required pieces are funnelled through a RESTful API request to the LESSoid service running localhost which return the compiled CSS as a JSON response.  If the LESSoid service is not running then it will automatically fall back to invokving lessc through the command line.  The lessc fall back prevents the loss of CSS on a site, but can be as slow as less.php.


## Requirements
* PHP 5.4 minimum, PHP 5.6 or higher recommend.
* Node.js 4.x or higher, may work on earlier versions, but is untested on them.
* A process manager service such as supervisord, god, launchctl, or otherwise.  A god configuration example is provided.
* Your poject's code checkout that contains all necessary LESS, CSS, and other requirements must be present on the server as the LESSoid service.


## How to use
Installation through composer into your project:

	composer require hydrawiki/lessoid

Without composer, download the latest release from the GitHub project and place it in an appropriate place in the project.  [https://github.com/HydraWiki/lessoid/releases]

## Current Development Status
This is alpha level code and the first target is to work seamlessly with MediaWiki.  There are various configuration settings that need to be implemented properly so it works not only seamlessly on MediaWiki, but on on frameworks as well.

It is currently a really dirty shim around the default Less_Parser class from less.php designed to trick MediaWiki into using it instead of less.php.

## Other Notes
[lessmodified]: We are currently using Less.js version 2.6.0 with a small modification to make it friendlier in a MediaWiki environemtn.  In the future we plan to eliminate this modification and have it user upgradeable through composer.
