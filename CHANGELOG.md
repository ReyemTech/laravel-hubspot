# Changelog

## 1.0.0 (2026-07-27)


### Features

* **01-01:** add composer-validate and manifest as named required checks ([f845708](https://github.com/ReyemTech/laravel-hubspot/commit/f845708aa2836b2c36ad1b0dbe2002e37413bce4))
* **01-01:** add six layer directories and single CI job ([9155eb3](https://github.com/ReyemTech/laravel-hubspot/commit/9155eb3d11be674ba26a1451af26503211c49331))
* **01-01:** expand CI to the 16-job matrix and wire the coverage floor ([d066180](https://github.com/ReyemTech/laravel-hubspot/commit/d06618086219bf001084c5aa3e887650c25d4b4f))
* **01-02:** add commitlint on every commit in a pull request ([59eb0cc](https://github.com/ReyemTech/laravel-hubspot/commit/59eb0cce083edc7c0d10445db1433e841f2dec21))
* **01-02:** add PR/issue templates and the governance workflow ([9aed4b8](https://github.com/ReyemTech/laravel-hubspot/commit/9aed4b85565120da202f7e6e8f9bc70d5fadc26d))
* **01-02:** add SECURITY.md, Dependabot and CODEOWNERS ([00e7bc7](https://github.com/ReyemTech/laravel-hubspot/commit/00e7bc7e0307c194baba8adc729597fa13995803))
* **01-03:** implement placeholder Phase 8 listener module ([ece98ae](https://github.com/ReyemTech/laravel-hubspot/commit/ece98ae67d31b6c0b5b95962885751312b50dfcf))
* **01-03:** wire the JS coverage floor as a required CI check ([953abbf](https://github.com/ReyemTech/laravel-hubspot/commit/953abbf330fe87ee4d35a55995bf3301df4a5ab0))
* **01-04:** wire the architecture suite and firing harness as required checks ([57b72a1](https://github.com/ReyemTech/laravel-hubspot/commit/57b72a100f954c6bbfcb2a4e637875e048ac78fd))
* **01-05:** add Pint and the PHPCS+Slevomat code-shape gate ([1896411](https://github.com/ReyemTech/laravel-hubspot/commit/1896411316754c4c038d505328637f560a66a430))
* **01-05:** configure phpstan at true max level with no baseline ([d8029df](https://github.com/ReyemTech/laravel-hubspot/commit/d8029dfb4e77fa6507e47cc98b14c73316357e01))
* **01-05:** implement the source-hygiene marker scan (D-07) ([1d78ba4](https://github.com/ReyemTech/laravel-hubspot/commit/1d78ba46bada89755b6e94bed0cbbe2f7b9f76df))
* **01-05:** wire the mutation floor and the quality.yml workflow ([40f1f7e](https://github.com/ReyemTech/laravel-hubspot/commit/40f1f7e01b9b1de22b8bea98a945b35dc06b3a94))
* **01-06:** stand up the Astro + Starlight docs site ([5d768a0](https://github.com/ReyemTech/laravel-hubspot/commit/5d768a09a75e3e577cb1396214eeafdca603a1df))
* **01-07:** composer audit and a greenfield-safe BC check ([c437a93](https://github.com/ReyemTech/laravel-hubspot/commit/c437a9311fff7037643b8c1603a694c12a16e68d))
* **01-07:** configure release-please (release-type: simple, no publish) ([065527d](https://github.com/ReyemTech/laravel-hubspot/commit/065527d30abffa07c70120e3cf8cc7dcc3d8b3d6))
* **01-07:** owner-gated checklist and the FOUND-03 probe (GREEN) ([3186bd7](https://github.com/ReyemTech/laravel-hubspot/commit/3186bd7ea0c37a46e54548ab9350aa7cf79123f1))
* **01-08:** implement the package ServiceProvider (GREEN) ([b4f19c8](https://github.com/ReyemTech/laravel-hubspot/commit/b4f19c8d3302da35a8334acc6032fff002460147))
* **ci:** add Dependabot auto-merge workflow ([40a1c58](https://github.com/ReyemTech/laravel-hubspot/commit/40a1c58e822643903f3208dcab5b9730f5910fa6))
* **ci:** add GitHub Pages docs deploy workflows ([e5f81d9](https://github.com/ReyemTech/laravel-hubspot/commit/e5f81d9a9931f9c02afe71fcd3853c12709e1631))


### Bug Fixes

* **01-05:** type-narrow the plan-01 CI tests for PHPStan level max ([2b8cb95](https://github.com/ReyemTech/laravel-hubspot/commit/2b8cb954499142e714c311f131525a065ad23f09))
* **01-06:** scope js.yml's install to resources/js only ([9afad1f](https://github.com/ReyemTech/laravel-hubspot/commit/9afad1f7df9da0c6e339cda8449db9f6927924d9))
* **01-07:** register the Arch testsuite in phpunit.xml.dist ([89ca792](https://github.com/ReyemTech/laravel-hubspot/commit/89ca792e3c4815399b9e333850afe95deeba5eeb))
* **ci:** drop Laravel 11 support, rectangular 12-job matrix ([8487beb](https://github.com/ReyemTech/laravel-hubspot/commit/8487bebdbb0d2871e620da11f8cb6af13c72d14c))
* **ci:** resolve each matrix cell with a full update instead of a partial one ([282542e](https://github.com/ReyemTech/laravel-hubspot/commit/282542e3450100a8bb02b8863d7ce002865d18d9))
* **ci:** resolve real GitHub Actions failures found on PR [#3](https://github.com/ReyemTech/laravel-hubspot/issues/3)'s first CI run ([0ba8d53](https://github.com/ReyemTech/laravel-hubspot/commit/0ba8d5350b53e96461dab9ceb0ebce723c824a83))
* **governance:** grant the commitlint job pull-requests: read ([b46e535](https://github.com/ReyemTech/laravel-hubspot/commit/b46e535f0234a6c01cb84bc4c16470b34dbc84ba))
* **js:** bump the coverage job's Node pin from 20 to 22 ([7ca8786](https://github.com/ReyemTech/laravel-hubspot/commit/7ca878660b3175cbcadfd3e6f07263a26ec6321b))
* **planning:** propagate the ^8.3 floor and 16-job matrix ([944bb00](https://github.com/ReyemTech/laravel-hubspot/commit/944bb00906628215d491c44617db6f80b43f3647))
* **planning:** stop phantom phase inflating milestone phase_count ([8cae724](https://github.com/ReyemTech/laravel-hubspot/commit/8cae724d702e1dd015aabfb446f49e13967a1f57))
* **standards:** raise PHP floor to ^8.3 so Pest 4 can be the only Pest ([3e0bea7](https://github.com/ReyemTech/laravel-hubspot/commit/3e0bea772188711aed505b732ec73c0311dd1b2e))
