# Changelog

## [0.6.0](https://github.com/ReyemTech/laravel-hubspot/compare/reyemtech/laravel-hubspot-v0.5.0...reyemtech/laravel-hubspot-v0.6.0) (2026-08-05)


### Features

* **04-06:** the delete policy, on the three events that distinguish it ([c9c7fa5](https://github.com/ReyemTech/laravel-hubspot/commit/c9c7fa506fb36467a41273ba6e031ab311d69eb8))
* **04-06:** the delete policy, on the three events that distinguish it ([0a19638](https://github.com/ReyemTech/laravel-hubspot/commit/0a196387ec09468bf8dcc61b0c6ca0919f5a0a05))
* **04-07:** one gate, consulted at dispatch and again on the worker ([7e73dea](https://github.com/ReyemTech/laravel-hubspot/commit/7e73deae0a870d51ef61eeafe764975390161b1e))
* **04-07:** one gate, consulted at dispatch and again on the worker ([c931cf1](https://github.com/ReyemTech/laravel-hubspot/commit/c931cf1043c31977c6b7bc1264596ff1e46b4459))
* **04-07:** support Laravel Octane, and say so in STANDARDS ([8207d3d](https://github.com/ReyemTech/laravel-hubspot/commit/8207d3d260bfd665330034b1f8acb51769a9d6c5))
* **04-08:** batch model sync requests ([85abb29](https://github.com/ReyemTech/laravel-hubspot/commit/85abb292bead387d2a3a98b7b28e60abf9e0b723))
* **04-09:** report bound models in doctor ([e5ebf5a](https://github.com/ReyemTech/laravel-hubspot/commit/e5ebf5a24fc139b8f6bce469fe4fa7014e68847f))
* **sync:** harden batch model synchronization ([dea569d](https://github.com/ReyemTech/laravel-hubspot/commit/dea569d08a1cce723951b92311ff0a5d07609443))


### Bug Fixes

* **04-06:** a purge archives once in total, not once per delete ([f1fd9f1](https://github.com/ReyemTech/laravel-hubspot/commit/f1fd9f112a034671758e96ddafe7a976e1ecf47d))
* **04-06:** a recreate creates nothing once a link exists again ([805b64e](https://github.com/ReyemTech/laravel-hubspot/commit/805b64ebe264b53982d1f57d69ea18734b84a38c))
* **04-06:** a recreate job whose model was deleted again creates nothing ([e535167](https://github.com/ReyemTech/laravel-hubspot/commit/e535167ae2c166b4272758e758630a3d15c80caf))
* **04-06:** cancel an archive its delete took back, and undo the flag a failed one caused ([ad8f8d9](https://github.com/ReyemTech/laravel-hubspot/commit/ad8f8d97d9b5277afee3baf9635a6a97715ef540))
* **04-06:** clear the stale flag on a successful resync, and recreate without a link ([ecbeaed](https://github.com/ReyemTech/laravel-hubspot/commit/ecbeaedbc6d3f42f5b9f96cc624bec536edc3a93))
* **04-06:** create through a transport that does not repeat what it cannot prove failed ([daea042](https://github.com/ReyemTech/laravel-hubspot/commit/daea042d4924c19ecdcde8e199d42bcbcbe5c3a6))
* **04-06:** give the archive marker and the archive one transaction's fate ([9844c4d](https://github.com/ReyemTech/laravel-hubspot/commit/9844c4d1335096f518454420de4ec4ceb6c925e9))
* **04-06:** honour the per-model opt-out on restore, and converge on a racing delete ([90529b0](https://github.com/ReyemTech/laravel-hubspot/commit/90529b0c6be1d672dcfee0fa61a153d917b99b5a))
* **04-06:** make the marker, the archive and their cleanup one deferred unit ([d3add0a](https://github.com/ReyemTech/laravel-hubspot/commit/d3add0aa946f71df67bc8012a6fb2d14220f57da))
* **04-06:** owe the restore response to the archive, not to the current gate ([361fd87](https://github.com/ReyemTech/laravel-hubspot/commit/361fd87acfc4f038085b3dbc08c9c4d76fcf0c37))
* **04-06:** record that this package archived a link, and decide from that ([b315afe](https://github.com/ReyemTech/laravel-hubspot/commit/b315afeb6cdbae775f222c4165cf68b6eda23950))
* **04-06:** replay a first sync an update would have made, not only a creation ([e649bf3](https://github.com/ReyemTech/laravel-hubspot/commit/e649bf3b81c48f06a1396c5c710cd779d8191a71))
* **04-06:** replay the delete policy when a sync raced a delete ([1108e56](https://github.com/ReyemTech/laravel-hubspot/commit/1108e56e15c678ae8c185b53a2d926d22d20ba27))
* **04-06:** replay the event that matches the delete that actually happened ([cba503b](https://github.com/ReyemTech/laravel-hubspot/commit/cba503bd4b565bf85cd09bcf965e739e68bc3cb4))
* **04-06:** stop deduplicating the purge archive, and create rather than upsert on recreate ([5c0bd5e](https://github.com/ReyemTech/laravel-hubspot/commit/5c0bd5ed4291ef7567c636ea04772c4bbe46e73e))
* **04-06:** take the archive marker back when publication fails, and repair a skipped initial sync ([79a21b6](https://github.com/ReyemTech/laravel-hubspot/commit/79a21b6eeac4a57bb517c64883ef63768138697a))
* **04-06:** write the archive marker before publishing the archive ([4091366](https://github.com/ReyemTech/laravel-hubspot/commit/40913661b6bb9618e5aca888c9793c3692350a7b))
* **04-07:** a suppressed archive takes its own marker back ([d693ae0](https://github.com/ReyemTech/laravel-hubspot/commit/d693ae0b48fd49a93a4d8dfe852d401abbaab531))
* **04-07:** advertise the suppression API on the facade, and gate the contract ([fc8bab6](https://github.com/ReyemTech/laravel-hubspot/commit/fc8bab69b6b491d3063cb84d1acf7dea496dec87))
* **04-07:** clean up AFTER the work, never before it ([b59f6ec](https://github.com/ReyemTech/laravel-hubspot/commit/b59f6ece2a023d45b7afa19ae526645f3fe63695))
* **04-07:** drop a [@var](https://github.com/var) that widened a type PHPStan already had right ([8444894](https://github.com/ReyemTech/laravel-hubspot/commit/8444894faaefd389d0c7bb4dcb6083fdeaf694f3))
* **04-07:** gate the dispatch on a restore, never the local bookkeeping ([c9d5151](https://github.com/ReyemTech/laravel-hubspot/commit/c9d5151283489185f8c2b75c7ee299bec0a82b83))
* **04-07:** put the real transport back at an Octane boundary, not just the flag ([cceaafd](https://github.com/ReyemTech/laravel-hubspot/commit/cceaafdf8a3a84005241cc319009342a4d562875))
* **04-07:** restore the stale flag and the pre-fake transport, not just their partners ([fdebe91](https://github.com/ReyemTech/laravel-hubspot/commit/fdebe913658b5ce9016b779060c9e28665a7c387))
* **04-08:** harden batch sync transport ([dd87173](https://github.com/ReyemTech/laravel-hubspot/commit/dd871738930ab7f3a49ef2028d9c07c33e2894e8))
* **04-08:** preserve identity in batch sync ([c557e0b](https://github.com/ReyemTech/laravel-hubspot/commit/c557e0bbe0e20853de6d629fe41ac3fa606d7982))
* **04-08:** reconcile batch delete races ([b80f07f](https://github.com/ReyemTech/laravel-hubspot/commit/b80f07f66b563656d01547dd28734d33897c58dd))
* a replacing fake inherits the original transport, it does not record the outgoing one ([1a8c8b0](https://github.com/ReyemTech/laravel-hubspot/commit/1a8c8b007b990764af13556dfaabd96a0a381ce0))
* **ci:** .npmrc controls both pnpm installs, so both gates must watch it ([49b6cbb](https://github.com/ReyemTech/laravel-hubspot/commit/49b6cbb8b2f0ad8e37e361bde1146743d6970d86))
* **ci:** apply the draft gate where the matrix context actually exists ([3cc5b7f](https://github.com/ReyemTech/laravel-hubspot/commit/3cc5b7f3abcbf115ff5ffe2c6f0437d256f2c349))
* **ci:** key non-PR concurrency on the commit, and diff the whole pushed range ([404176e](https://github.com/ReyemTech/laravel-hubspot/commit/404176ed220a546d9a9ceba9a2badf9587b6f234))
* **ci:** name the diff range mode, because the two callers ask different questions ([6a86951](https://github.com/ReyemTech/laravel-hubspot/commit/6a86951c8188da7bcaaf35c1e8e64f9166412606))
* **ci:** read raw pathnames, because git C-quotes anything but ASCII ([3e2efb3](https://github.com/ReyemTech/laravel-hubspot/commit/3e2efb34932d84a55fc10438d4e060f7aa5ded2b))
* **ci:** read raw pathnames, because git C-quotes anything but ASCII ([2e7e889](https://github.com/ReyemTech/laravel-hubspot/commit/2e7e8893aa885fcc2361cdb141afcfcc905ecb81))
* **ci:** see a file renamed out of a gated directory, and prove it ([7a3c611](https://github.com/ReyemTech/laravel-hubspot/commit/7a3c611cc205a55564c1e1260458aa060c931c19))
* inspect renamed paths in re-review proof ([79bb853](https://github.com/ReyemTech/laravel-hubspot/commit/79bb8536ee0a421c1449047b047e3e54c92e2487))
* **sync:** defer batch dispatch and validate upsert results ([9e9c50f](https://github.com/ReyemTech/laravel-hubspot/commit/9e9c50ffe4e171a4c16212292041c912295b590e))
* **sync:** log local batch error references ([aca10c6](https://github.com/ReyemTech/laravel-hubspot/commit/aca10c69516c2098cf67ac40205e178845d2330b))
* **sync:** normalize batch error email contexts ([2e6c5e8](https://github.com/ReyemTech/laravel-hubspot/commit/2e6c5e86a2c3a901794716a156147410c8959de1))
* **sync:** redact batch error context from logs ([c472318](https://github.com/ReyemTech/laravel-hubspot/commit/c472318a822bacb5b3800b0418ebb698bb909e3e))
* **sync:** reject unsafe batch outcomes ([5d64455](https://github.com/ReyemTech/laravel-hubspot/commit/5d64455de1ecbe275a11f8f6ac881e37569c36fa))
* **sync:** reject unsafe direct batch jobs ([5910e34](https://github.com/ReyemTech/laravel-hubspot/commit/5910e348f610ee42b87c21696838d176e961762f))
* **sync:** reload batch models on selected connection ([ff08c6f](https://github.com/ReyemTech/laravel-hubspot/commit/ff08c6f5dd0a1a9e145f167b6a11586af08b7f53))
* **sync:** rely on Laravel link race handling ([f963e97](https://github.com/ReyemTech/laravel-hubspot/commit/f963e97da1342bd3e5159d51d0036783faaa0ccb))
* **sync:** share delete-race reconciliation ([fb6ea4d](https://github.com/ReyemTech/laravel-hubspot/commit/fb6ea4dac6e521322a160e9777cfb8ca2915a671))
* **sync:** validate exact batch classes and normalized email keys ([8b7476f](https://github.com/ReyemTech/laravel-hubspot/commit/8b7476f7cafaca22a6bc2c1dabd993b267ad61df))
* the archive marker needs a property default, not a promoted one ([d39281e](https://github.com/ReyemTech/laravel-hubspot/commit/d39281e297092e5a78ff78d55bd34f4bf6925f17))
* withdraw the archive marker without global scopes ([e6ca6c6](https://github.com/ReyemTech/laravel-hubspot/commit/e6ca6c691775927b6a82e2ed3eec15722dad4e24))


### Reverts

* **04-06:** withdraw on_restore =&gt; 'recreate' from this release ([8ea4c80](https://github.com/ReyemTech/laravel-hubspot/commit/8ea4c80a779263fe94d4fece3279ecdb5de1e157))

## [0.5.0](https://github.com/ReyemTech/laravel-hubspot/compare/reyemtech/laravel-hubspot-v0.4.0...reyemtech/laravel-hubspot-v0.5.0) (2026-07-31)


### Features

* **04-05:** gate created and updated behind three independent switches ([536184d](https://github.com/ReyemTech/laravel-hubspot/commit/536184df74b23e74d456eac4370f0bab87ea4c88))


### Bug Fixes

* **04-05:** honour auto_sync.queue, and keep the observer constructor stable ([19821d1](https://github.com/ReyemTech/laravel-hubspot/commit/19821d1dbc279939ef805d2f32cddda9ec8800c1))
* **04-05:** identify soft-deleting models by their scope, not a method name ([629c683](https://github.com/ReyemTech/laravel-hubspot/commit/629c683ba2f05a21adacb75917b07c29110421bb))
* **04-05:** require a real restore, and check the trait rather than the method name ([997b5f7](https://github.com/ReyemTech/laravel-hubspot/commit/997b5f7af62454364ed33167a8066625d24a6a6d))
* **04-05:** resolve the restore guard's column through getDeletedAtColumn() ([fb89166](https://github.com/ReyemTech/laravel-hubspot/commit/fb891666a69542002eb1f24c2fb5f3e221a0cc10))
* **tests:** empty the per-process publish directories before each boot ([381185d](https://github.com/ReyemTech/laravel-hubspot/commit/381185d531e3526eb338ecea835322d131e404ce))
* **tests:** publish into per-process directories, not the shared skeleton ([7995017](https://github.com/ReyemTech/laravel-hubspot/commit/7995017ae4b4c7527fc2f4ff2300528dc55bc595))
* **tests:** remove stale "inside the Testbench skeleton" comment ([22d12e5](https://github.com/ReyemTech/laravel-hubspot/commit/22d12e534850133be62d26f7e427c2a0d842f29c))

## [0.4.0](https://github.com/ReyemTech/laravel-hubspot/compare/reyemtech/laravel-hubspot-v0.3.1...reyemtech/laravel-hubspot-v0.4.0) (2026-07-31)

> **Note on the four `04-04` cross-connection entries below.** They are four attempts at one
> problem, not four separate improvements, and two of them were reverted before this release was
> cut. This list is generated from commit history, so it records the route rather than the
> destination.
>
> What actually ships is `bc30d9b`. When a bound model and `hubspot_object_links` live on different
> database connections, `whereHubspotId()`, `syncedToHubspot()` and `pendingHubspotSync()` resolve
> the link rows on the link table's own connection and constrain the model query by key. That
> resolution is **eager** — it happens when the scope is called, not when the query runs.
>
> `95d116c` ("when the query runs, not when it is built") and `e840490` ("splice the deferred
> constraint") describe a deferred version that was reverted by `bc30d9b`: it broke inside nested
> predicates and let a link leg bypass global scopes such as `SoftDeletes`. Neither is the behaviour
> of this release, and this note exists so the entries below are not read as if they were.

### Features

* **04-01:** add D-04's bidirectional vendor-namespace gate and widen R3 for Illuminate ([b02fe11](https://github.com/ReyemTech/laravel-hubspot/commit/b02fe11a9b1798ceb472aaaa622b07ffa34c872e))
* **04-02:** wire the Model Sync tracer end to end ([259962a](https://github.com/ReyemTech/laravel-hubspot/commit/259962ae548063d2aef23bcaecc03e0801bb70a8))
* **04-03:** resolve dot-notation, closure and update-map forms ([bdbcc4d](https://github.com/ReyemTech/laravel-hubspot/commit/bdbcc4d16a35f1d44b01a5c252ba596990132a35))
* **04-03:** update an already-linked model by its stored id ([bea6b43](https://github.com/ReyemTech/laravel-hubspot/commit/bea6b4307ab76fdb41863e9d7da198d817164053))
* **04-04:** the trait's three scopes and the unbound-model throw ([c053fb1](https://github.com/ReyemTech/laravel-hubspot/commit/c053fb100606dc0ff37c9ffb9f367cd38cdaa8d1))
* **deps:** declare guzzlehttp/psr7, scope PHPUnit to src/Testing/ ([1137b3e](https://github.com/ReyemTech/laravel-hubspot/commit/1137b3e0496983b1ae7e1b86bb97f85621d7313b))
* resolve every $hubspotMap form, and update by the stored id ([3e5697e](https://github.com/ReyemTech/laravel-hubspot/commit/3e5697e761e2c37c529f8d21316b16a5058c1721))
* sync a bound model to HubSpot, end to end ([9544c71](https://github.com/ReyemTech/laravel-hubspot/commit/9544c7160df26b105494e8833ff912375ee87cea))


### Bug Fixes

* **04-01:** check every declared illuminate package, and tokenize like the gate ([849f7c9](https://github.com/ReyemTech/laravel-hubspot/commit/849f7c93a6bd08392be257979d2fa3b8ac2521ed))
* **04-01:** declare the four illuminate requires, one of them a fix (D-19) ([03b20d9](https://github.com/ReyemTech/laravel-hubspot/commit/03b20d96f445cafb377b6c701aed3e6ba4ee38ea))
* **04-01:** reassemble group-use imports before classifying vendor roots ([d42c40e](https://github.com/ReyemTech/laravel-hubspot/commit/d42c40e26a3e88166dff27f58d03a279027cb015))
* **04-02:** close four Codex P2 findings on PR [#39](https://github.com/ReyemTech/laravel-hubspot/issues/39) (GREEN) ([95a11d2](https://github.com/ReyemTech/laravel-hubspot/commit/95a11d22b296427710f5746a5b29a63bf9294061))
* **04-02:** close three silent tracer failures found on PR [#39](https://github.com/ReyemTech/laravel-hubspot/issues/39) (GREEN) ([5f30676](https://github.com/ReyemTech/laravel-hubspot/commit/5f30676c79150b3c0521354b6f92d9cb8133d529))
* **04-03:** let R3 admit data_get, an unnamespaced Illuminate helper ([333c4b6](https://github.com/ReyemTech/laravel-hubspot/commit/333c4b6a327e9f5a592be6934ae0aba624ea7426))
* **04-03:** read $hubspotUpdateMap from the model, closing SYNC-02 properly ([d719058](https://github.com/ReyemTech/laravel-hubspot/commit/d719058aa2ff504f6e09b0236b9dcc2f02afc059))
* **04-04:** assert exception messages as literals, not factory-vs-factory ([da165dc](https://github.com/ReyemTech/laravel-hubspot/commit/da165dcef4b581641c50a23bd7f1e1215a02764c))
* **04-04:** resolve cross-connection links through the ordinary builder API ([bc30d9b](https://github.com/ReyemTech/laravel-hubspot/commit/bc30d9b46d742a4b1d45203b570380347c2fd56b))
* **04-04:** resolve cross-connection links when the query runs, not when it is built ([95d116c](https://github.com/ReyemTech/laravel-hubspot/commit/95d116cf6dbe38cf7d62ee18204a5e63b9901a8a))
* **04-04:** resolve the scopes on the link table's own connection ([fe03728](https://github.com/ReyemTech/laravel-hubspot/commit/fe037280b7c378ed901932c3e937e00229802bc0))
* **04-04:** splice the deferred link constraint in at the scope's position ([e840490](https://github.com/ReyemTech/laravel-hubspot/commit/e84049098217cfc3f57f015bb3c15d3dade79a76))
* **ci:** fail closed when the mutation scope cannot be computed ([6ea8c87](https://github.com/ReyemTech/laravel-hubspot/commit/6ea8c87eee7ab69c3ec051149ac4d76960e5cf17))
* **ci:** make the resolver say "I don't know" instead of guessing ([b82b91e](https://github.com/ReyemTech/laravel-hubspot/commit/b82b91edfc27673c8c9ba9f79d9a09f4f0096799))
* **ci:** reassemble group use once, so both scanners cannot disagree ([674b022](https://github.com/ReyemTech/laravel-hubspot/commit/674b022558f21724ca9e0344f21ea6dc99fe96b7))
* **ci:** replace the invalid zero-depth fetch with an explicit refspec ([ad3cd46](https://github.com/ReyemTech/laravel-hubspot/commit/ad3cd4618dc30cae08a908f243497ae688e03a7e))
* **ci:** split the PSR-4 reader so it clears the complexity ceiling ([d995855](https://github.com/ReyemTech/laravel-hubspot/commit/d9958559df367d576b8f168202b880348b0c847e))
* declare what src/ actually names, and scope what cannot be declared ([8d9a247](https://github.com/ReyemTech/laravel-hubspot/commit/8d9a2473b7b1829c64835ba67a34221ffe98feb6))
* **deps:** declare the packages that own the namespaces, not just the root ([df7b49c](https://github.com/ReyemTech/laravel-hubspot/commit/df7b49ca1a1cabf547e9cb5542d5c12c40f3f71d))
* **deps:** widen promises to what guzzle ^7.3 permits, and skip symbol imports ([0a8df86](https://github.com/ReyemTech/laravel-hubspot/commit/0a8df86477566168c87a66abe310d1888b030cd8))

## [0.3.1](https://github.com/ReyemTech/laravel-hubspot/compare/reyemtech/laravel-hubspot-v0.3.0...reyemtech/laravel-hubspot-v0.3.1) (2026-07-30)


### Bug Fixes

* assert against the records this probe created ([e3f6b5c](https://github.com/ReyemTech/laravel-hubspot/commit/e3f6b5c8a90c7e77acc265784bff962515ce249d))
* bound how much of hubspot's explanation reaches the message ([4f7920d](https://github.com/ReyemTech/laravel-hubspot/commit/4f7920d6d519b3726337cd99ac6d56f46f218514))
* carry the deserialised error object onto the rebuilt exception ([47182db](https://github.com/ReyemTech/laravel-hubspot/commit/47182db61f21d25afaa475554f61e55f2e08ea3c))
* check each seeded type, and record what that found ([ec50ea3](https://github.com/ReyemTech/laravel-hubspot/commit/ec50ea32e86bbf1c0a3b6afc6eb2ed19e3c22c4e))
* confirm the update instead of trusting the response ([d65e057](https://github.com/ReyemTech/laravel-hubspot/commit/d65e0575271ba186e772e396ea700cc82dce8f02))
* decide on this attempt's results, merge each page as it arrives ([7f6aaa8](https://github.com/ReyemTech/laravel-hubspot/commit/7f6aaa81cc037a5026feaa70b2c322ec84df57be))
* end the sweep on tracked ids being visible, not on a count ([74be152](https://github.com/ReyemTech/laravel-hubspot/commit/74be152a56f1c83dc12dc824bb015931c0c69a8c))
* keep found records when a later sweep attempt fails ([4b61c84](https://github.com/ReyemTech/laravel-hubspot/commit/4b61c84af2469dc3866ce9fe15932b6a2c8ebbf1))
* keep getprevious()'s type while dropping the inlined body ([f4d0a0b](https://github.com/ReyemTech/laravel-hubspot/commit/f4d0a0b79d643cfca85e7ba04428e8264cae7029))
* keep hubspot's echoed text out of the message and the string cast ([7637331](https://github.com/ReyemTech/laravel-hubspot/commit/763733161c45978806dc44c1bce48ab3c0ab2fdd))
* key the seeded lookup by category, and prove the dissociate happened ([8412646](https://github.com/ReyemTech/laravel-hubspot/commit/8412646e235fce579f68e690db5917666aec3318))
* key tracked records by type, and page the sweep ([3f98fe7](https://github.com/ReyemTech/laravel-hubspot/commit/3f98fe7d25ed60714c45cb7912f341573092c2b1))
* let a 4xx message carry hubspot's own explanation ([832e858](https://github.com/ReyemTech/laravel-hubspot/commit/832e858d0d2660dc44d8ffbfa76c56ef5cfd7954))
* let a 4xx message carry HubSpot's own explanation ([00d643a](https://github.com/ReyemTech/laravel-hubspot/commit/00d643a6e3ee43f8f63fd37bfec4fa3ebea80e6b))
* make the probe fail when its own claims do not hold ([63ae47e](https://github.com/ReyemTech/laravel-hubspot/commit/63ae47e76483dcd3e65784110df53b70b96bf7ce))
* make the sweep survive its own stopping condition ([7388233](https://github.com/ReyemTech/laravel-hubspot/commit/73882337eda6517c989c2fc49e8cdb68f930de01))
* make the unscrubbed paths safe by default ([c3e30f6](https://github.com/ReyemTech/laravel-hubspot/commit/c3e30f611dd211440da98e08fa1a3866d63f8b36))
* narrow the sdk response object before asserting on it ([059ef3c](https://github.com/ReyemTech/laravel-hubspot/commit/059ef3c7562a3ef4fea63d4f91661e63ebeefb25))
* normalise unicode separators, not just ascii controls ([ce99d93](https://github.com/ReyemTech/laravel-hubspot/commit/ce99d9310bfcc185e62c7ab3e3fe2e263b7a227f))
* pin reason truncation to utf-8 ([7b83945](https://github.com/ReyemTech/laravel-hubspot/commit/7b83945f5d6a448ec6f8c66412e9085575612681))
* poll the whole window, not until the tracked ids show up ([7582a78](https://github.com/ReyemTech/laravel-hubspot/commit/7582a78b7b939aea3a3d7a987bfc17db8a721d1c))
* reject the inverse id on each direction, not just require the right one ([76b5898](https://github.com/ReyemTech/laravel-hubspot/commit/76b58985978adf961fb4fb959dc8e4937dc22a67))
* report the last attempt's count in the sweep warning ([8c0809a](https://github.com/ReyemTech/laravel-hubspot/commit/8c0809a03ecc7b1acf230ae0f15efaeedc0430be))
* sanitise the retained chain, not just the string cast ([c66457c](https://github.com/ReyemTech/laravel-hubspot/commit/c66457c29ce7dcd2723d182d85478e2e3f0044c9))
* stop the bc check comparing a release against itself ([7fab6c7](https://github.com/ReyemTech/laravel-hubspot/commit/7fab6c7800560c76abe538d01695e7c00d412e5a))
* stop the bc check comparing a release against itself ([54a9322](https://github.com/ReyemTech/laravel-hubspot/commit/54a9322459f860ac80c23f9c56e5fd1990e1fa5d))
* stop the translator retaining the credentials it scrubs ([96e4355](https://github.com/ReyemTech/laravel-hubspot/commit/96e4355687db881fa281b4372f70e1b6a22b194a))
* sweep for records a lost response left untracked ([6c30295](https://github.com/ReyemTech/laravel-hubspot/commit/6c30295407b4ba0a6eb566431917bedec6a3063a))
* union sweep results by id instead of replacing them ([c923716](https://github.com/ReyemTech/laravel-hubspot/commit/c923716f286562c984b259df3059189d10f91b31))

## [0.3.0](https://github.com/ReyemTech/laravel-hubspot/compare/reyemtech/laravel-hubspot-v0.2.0...reyemtech/laravel-hubspot-v0.3.0) (2026-07-29)


### Features

* **03-01:** normalise hubspot object types to canonical identifiers ([a5bbad1](https://github.com/ReyemTech/laravel-hubspot/commit/a5bbad1f60af31c50319f7d5bbc9ef524c3ea873))
* **03-01:** resolve labelled writes from the registry, by rebinding one key ([5879851](https://github.com/ReyemTech/laravel-hubspot/commit/58798517e711bfd4fc53f8388cfe7c8da74c053a))
* **03-01:** seed the cited baseline map and open the store seam ([825f56b](https://github.com/ReyemTech/laravel-hubspot/commit/825f56b85f892fa7182b213bf02f3696f8d8b26c))
* **03-02:** add the database association type store and its migration ([04b7d47](https://github.com/ReyemTech/laravel-hubspot/commit/04b7d471b6794faf4a76287148a33abd53e1e2a6))
* **03-02:** database association type store and zero-migration install ([cfa79a3](https://github.com/ReyemTech/laravel-hubspot/commit/cfa79a341c4c01b3e1d3369cfb2a5bb3389ff681))
* **03-02:** publish the package migration and generalise the gated loading ([bd1d8ee](https://github.com/ReyemTech/laravel-hubspot/commit/bd1d8ee5ee73cae48ec35c734bf8f6ff6b3ab7bc))
* **03-03:** association definitions, hubspot:associations:sync, and the two doctors ([ed2c493](https://github.com/ReyemTech/laravel-hubspot/commit/ed2c4939ed2cacd0a9dd3f21dc7e9090e369bd34))
* **03-03:** read a portal's association definitions through the gateway ([e485207](https://github.com/ReyemTech/laravel-hubspot/commit/e48520781d36fb8bb182149c4ddda1da5a37aa34))
* **03-03:** reconcile a portal's association labels with hubspot:associations:sync ([9d01548](https://github.com/ReyemTech/laravel-hubspot/commit/9d01548323e9b3c605c9dcaf4f5d4c9343d27a3c))
* **03-03:** report reconciled rows the portal no longer returns, without removing them ([d9e3437](https://github.com/ReyemTech/laravel-hubspot/commit/d9e34379a3e314dd808d9de2814e553f33dc3055))
* **03-03:** ship hubspot:doctor and hubspot:associations:doctor ([2864507](https://github.com/ReyemTech/laravel-hubspot/commit/2864507070a773853e6229dceef3fc2fd58c926c))


### Bug Fixes

* **03-01:** refuse an aliased pair, and stop the cache store answering stale ([365045d](https://github.com/ReyemTech/laravel-hubspot/commit/365045dfb6d8b0745cd3611548f268728750cb08))
* **03-02:** index the lookup key as a digest so no collation can fold it ([b971c37](https://github.com/ReyemTech/laravel-hubspot/commit/b971c372dbee81c2519135f867a09e550a14d652))
* **03-03:** keep a verified inverse id across a sync, and name the paging caveat ([dfde91f](https://github.com/ReyemTech/laravel-hubspot/commit/dfde91fa81c2ce44e5bf7e91b5bd00f34b0b44f9))

## [0.2.0](https://github.com/ReyemTech/laravel-hubspot/compare/reyemtech/laravel-hubspot-v0.1.0...reyemtech/laravel-hubspot-v0.2.0) (2026-07-29)


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
* **02-01:** Gateway tracer — deals create through Hubspot::fake() with zero HTTP ([03f8dbf](https://github.com/ReyemTech/laravel-hubspot/commit/03f8dbf5756708f7df4736ee46f3768ed1d5eb5a))
* **02-01:** green state — deals create through ObjectGateway with zero HTTP ([a2ba3ee](https://github.com/ReyemTech/laravel-hubspot/commit/a2ba3ee83a6abf7fccb011e9ac87c49ea81da78e))
* **02-02:** Gateway error hierarchy and deliberate production transport ([9af7c0a](https://github.com/ReyemTech/laravel-hubspot/commit/9af7c0a833ca4108f43c1a2886b10b1adc7d5003))
* **02-02:** green state — deliberate production transport: timeout and retries ([0ddc59d](https://github.com/ReyemTech/laravel-hubspot/commit/0ddc59d54f509bf769743651cb433f91dba6e0f6))
* **02-02:** green state — the four-member exception hierarchy and a namespace-complete translator ([51d3a52](https://github.com/ReyemTech/laravel-hubspot/commit/51d3a52894d34cd9a7f490b83717999d98880d38))
* **02-03:** green state — batch operations, one-item-batch upsert and HTTP 207 ([8c6596e](https://github.com/ReyemTech/laravel-hubspot/commit/8c6596e866382694bd3a0604562a25e4ace3039b))
* **02-03:** green state — the whole single-object surface over any object type ([7490d5f](https://github.com/ReyemTech/laravel-hubspot/commit/7490d5f48708a242660e23262c87301331b1940b))
* **02-03:** the generic object core — any object type, batch, and HTTP 207 as partial failure ([fa2d517](https://github.com/ReyemTech/laravel-hubspot/commit/fa2d517a7a39e53b398c42b63ca15e6343c6d24f))
* **02-04:** green state — ObjectRef and the directed AssociationPair primitive ([7a192ff](https://github.com/ReyemTech/laravel-hubspot/commit/7a192ff1f447f085b1f1d44baddb38ee30568fe3))
* **02-04:** green state — unlabelled directional associate, dissociate and read ([21bb30e](https://github.com/ReyemTech/laravel-hubspot/commit/21bb30eb160f24b58ddf18fbc401813b12bc388f))
* **02-04:** the directed pair primitive and the unlabelled association path ([53b1608](https://github.com/ReyemTech/laravel-hubspot/commit/53b1608785e0ec8a286e6c9548a37c17046eb304))
* **02-05:** green state — bidirectional on the unlabelled path too ([1f9f761](https://github.com/ReyemTech/laravel-hubspot/commit/1f9f7612b64cb2ab7279650bb7e8655dd1258374))
* **02-05:** green state — labelled writes that throw rather than write the inverse ([55bd132](https://github.com/ReyemTech/laravel-hubspot/commit/55bd132f8eb688b8750f59a1aec73adb112f36c4))
* **02-05:** green state — the association type value object and the resolver seam ([8ecceaa](https://github.com/ReyemTech/laravel-hubspot/commit/8ecceaa682575ad2830ee050bd849156af940437))
* **02-05:** labelled associations, the resolver seam, and the never-the-inverse guarantee ([e9fbac5](https://github.com/ReyemTech/laravel-hubspot/commit/e9fbac5f08fc28c6ebe372e7f94c6775cec71413))
* **02-06:** assertAssociated, asserting the directional type id from the wire ([058b20d](https://github.com/ReyemTech/laravel-hubspot/commit/058b20d0561f6ceff9808d3034162c3d578f15f8))
* **02-06:** assertSynced, assertNothingSynced and a request log that names what it saw ([91ad80f](https://github.com/ReyemTech/laravel-hubspot/commit/91ad80f54f953a62b5066642a2da8e654b4da285))
* **02-06:** default responses carry timestamps from the test clock ([ea0e985](https://github.com/ReyemTech/laravel-hubspot/commit/ea0e985dab6b5cbe52de569c29e4b5f9cfc2b11f))
* **02-06:** the fake's assertion surface, and the test that fails if the inverse type id is written ([3819908](https://github.com/ReyemTech/laravel-hubspot/commit/3819908a3bc4e5aeb28b5a36b211aa6d139d31dd))
* **ci:** add Dependabot auto-merge workflow ([40a1c58](https://github.com/ReyemTech/laravel-hubspot/commit/40a1c58e822643903f3208dcab5b9730f5910fa6))
* **ci:** add GitHub Pages docs deploy workflows ([e5f81d9](https://github.com/ReyemTech/laravel-hubspot/commit/e5f81d9a9931f9c02afe71fcd3853c12709e1631))
* **ci:** implement the review-thread reply gate (STANDARDS §12) ([0431f5b](https://github.com/ReyemTech/laravel-hubspot/commit/0431f5b5f7f78a7c26892d057f41bd4a417a3238))
* **ci:** wire check-review-threads.sh as a required check ([e8fafab](https://github.com/ReyemTech/laravel-hubspot/commit/e8fafab4b4e0c95b2330d73a9d2cbf0daab66729))


### Bug Fixes

* **01-05:** type-narrow the plan-01 CI tests for PHPStan level max ([2b8cb95](https://github.com/ReyemTech/laravel-hubspot/commit/2b8cb954499142e714c311f131525a065ad23f09))
* **01-06:** scope js.yml's install to resources/js only ([9afad1f](https://github.com/ReyemTech/laravel-hubspot/commit/9afad1f7df9da0c6e339cda8449db9f6927924d9))
* **01-07:** register the Arch testsuite in phpunit.xml.dist ([89ca792](https://github.com/ReyemTech/laravel-hubspot/commit/89ca792e3c4815399b9e333850afe95deeba5eeb))
* **02-02:** green state — fromConfig() defaults preserve the token-only call signature ([77d39ef](https://github.com/ReyemTech/laravel-hubspot/commit/77d39ef04a06d92f75da1d9644fc9e5f19f73bb7))
* **02-03:** green state — derive partial-batch status from the 207, not from the error list ([402b491](https://github.com/ReyemTech/laravel-hubspot/commit/402b491806e7f73f76cd667ae63a60ef74c54228))
* **02-04:** correct the indefinite article in the non-string object reference message ([82a69a1](https://github.com/ReyemTech/laravel-hubspot/commit/82a69a11a84f4a9485eec92c3d64d72eff7c9daa))
* **02-04:** narrow associate()'s response shape and reject a non-string ObjectRef side ([d9768f8](https://github.com/ReyemTech/laravel-hubspot/commit/d9768f81640de7de0764613356ebd5067daf2afa))
* **02-05:** every layer may throw the package exception hierarchy ([1efa0ae](https://github.com/ReyemTech/laravel-hubspot/commit/1efa0aecc28a5da410911e73ff4e8f0ff9e81b9a))
* **02-05:** the labelled reverse write resolves its own direction's labels ([a07c33e](https://github.com/ReyemTech/laravel-hubspot/commit/a07c33eedc14a056c1346375e191a52cca2cdc21))
* **02-06:** require one record to carry the whole property subset (Codex P1) ([6c7cfa4](https://github.com/ReyemTech/laravel-hubspot/commit/6c7cfa4586ef51ef988e0e4da27cad46ee15ac5d))
* address seven dismissed Codex review findings ([c51789b](https://github.com/ReyemTech/laravel-hubspot/commit/c51789b2278fb58bd65ffbb47624cadb806eb5af))
* **ci:** detect positional composer package specs appended after --with ([caa2bf4](https://github.com/ReyemTech/laravel-hubspot/commit/caa2bf4393198ed7e71e03a979ae87cf7fce80ff))
* **ci:** drop Laravel 11 support, rectangular 12-job matrix ([8487beb](https://github.com/ReyemTech/laravel-hubspot/commit/8487bebdbb0d2871e620da11f8cb6af13c72d14c))
* **ci:** paginate every review thread's comments, not just the first 50 ([4189fe0](https://github.com/ReyemTech/laravel-hubspot/commit/4189fe022e50bd3f92dd29656ca2bd066e03b70b))
* **ci:** re-trigger review-threads on thread resolution, not just push ([3d3b8ba](https://github.com/ReyemTech/laravel-hubspot/commit/3d3b8ba6d9379520cf3949705db1eee540c23cd1))
* **ci:** resolve each matrix cell with a full update instead of a partial one ([282542e](https://github.com/ReyemTech/laravel-hubspot/commit/282542e3450100a8bb02b8863d7ce002865d18d9))
* **ci:** resolve real GitHub Actions failures found on PR [#3](https://github.com/ReyemTech/laravel-hubspot/issues/3)'s first CI run ([0ba8d53](https://github.com/ReyemTech/laravel-hubspot/commit/0ba8d5350b53e96461dab9ceb0ebce723c824a83))
* **ci:** revert pull_request_review_thread -- not a valid Actions trigger ([b558042](https://github.com/ReyemTech/laravel-hubspot/commit/b5580423b73b7851acef34220161c05dded43e35))
* **ci:** trigger dependabot-auto-merge on pull_request_target ([bf8cda3](https://github.com/ReyemTech/laravel-hubspot/commit/bf8cda3e3e651805d5420c97c800aae818e2893f))
* **docs:** require workflow scope on RELEASE_TOKEN and retrigger on pages change ([d371b12](https://github.com/ReyemTech/laravel-hubspot/commit/d371b12cd4903746406f28cb7edf10e8c1abfa42))
* **governance:** grant the commitlint job pull-requests: read ([b46e535](https://github.com/ReyemTech/laravel-hubspot/commit/b46e535f0234a6c01cb84bc4c16470b34dbc84ba))
* **js:** bump the coverage job's Node pin from 20 to 22 ([7ca8786](https://github.com/ReyemTech/laravel-hubspot/commit/7ca878660b3175cbcadfd3e6f07263a26ec6321b))
* **planning:** propagate the ^8.3 floor and 16-job matrix ([944bb00](https://github.com/ReyemTech/laravel-hubspot/commit/944bb00906628215d491c44617db6f80b43f3647))
* **planning:** stop phantom phase inflating milestone phase_count ([8cae724](https://github.com/ReyemTech/laravel-hubspot/commit/8cae724d702e1dd015aabfb446f49e13967a1f57))
* **probe:** address four Codex findings — credential message, payload conflation, stale plan ([6bde35c](https://github.com/ReyemTech/laravel-hubspot/commit/6bde35cdf733cd5217ae48bf2f7bf525771251b6))
* **probe:** refuse the unlabelled default type and use an email domain HubSpot accepts ([5326183](https://github.com/ReyemTech/laravel-hubspot/commit/5326183c63ae8fc3d87f8c54aa8ef2a429b37513))
* **probe:** run FOUND-03, record the answer, and move token guidance to Service Keys ([51a2c2a](https://github.com/ReyemTech/laravel-hubspot/commit/51a2c2a1794d09a6ee067c03f4f0518efe4aa7a4))
* **release:** harden publish-docs.sh's push_attempt ([9e845ce](https://github.com/ReyemTech/laravel-hubspot/commit/9e845ceac6b4747a77fd6fc18e5f22be6cda8254))
* **release:** keep versioning below 1.0.0 until the API is real ([60ba775](https://github.com/ReyemTech/laravel-hubspot/commit/60ba775cb236533541e05b23014deb3f41449671))
* **release:** keep versioning below 1.0.0 until the API is real ([f44ef72](https://github.com/ReyemTech/laravel-hubspot/commit/f44ef7258edb668c4b2f2bf92ded466117b65d09))
* **release:** stop downgrading feature commits to patch bumps ([8b4b8c0](https://github.com/ReyemTech/laravel-hubspot/commit/8b4b8c03d939865600260bdc45ade3213fc8c3ce))
* **standards:** raise PHP floor to ^8.3 so Pest 4 can be the only Pest ([3e0bea7](https://github.com/ReyemTech/laravel-hubspot/commit/3e0bea772188711aed505b732ec73c0311dd1b2e))
