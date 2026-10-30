---
paths:
  - "tests/**/*.php"
---

# Testing

**Private services are inlined in the test container:** `VulnerabilityAccess` (and any other service not used outside its hosts at boot) is compiled away in the test kernel, so `$this->client->getContainer()->get(VulnerabilityAccess::class)` throws `ServiceNotFoundException`. Workaround: instantiate the service manually with publicly-available dependencies — e.g. `new VulnerabilityAccess($this->client->getContainer()->get('security.helper'))`. `security.helper` is the public alias for `Symfony\Bundle\SecurityBundle\Security`.

**Doctrine collection inverse-side hydration in tests:** `$user->addUserGroup($ug)` does NOT automatically populate `$ug->users` on the in-memory inverse side. For access checks that traverse both directions, call both sides explicitly (`$user->addUserGroup($ug); $assetType->addUserGroup($ug);`), or flush + refresh. Reference: `tests/Functional/VulnerabilityAccessTest.php`.

**Functional test HTTP requests bypass Turbo:** `Client::request()` does not send `Accept: text/vnd.turbo-stream.html`, so controllers branching on `TurboBundle::STREAM_FORMAT` must keep the non-Turbo redirect path working — tests will hit the redirect branch, not the stream branch.
