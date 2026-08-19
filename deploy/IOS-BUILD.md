# Building Dana for iOS with Codemagic

You are on Windows with no Mac. Codemagic builds on its own hosted Mac
minis, so **no Mac is needed to compile**. The constraint is not building
— it is *installing*.

---

## The honest answer up front

| Goal | Apple Developer Program ($99/yr)? | Works from Windows alone? |
|---|---|---|
| Prove the iOS build compiles | **No** | Yes |
| Run in the iOS Simulator | No | No — needs a Mac |
| Install on **your own** iPhone | **No**, with caveats (below) | Yes, via sideloading |
| Ad-hoc install for other testers | **Yes** | Yes |
| TestFlight | **Yes** | Yes |
| App Store | **Yes** | Yes |

The widely repeated claim "you must pay $99 to put an app on a real
iPhone" is **not quite true**. Apple lets a *free* Apple ID sign an app
for a device you control, with hard limits:

- the signature **expires after 7 days**, then the app refuses to launch
- **3 sideloaded apps** at a time
- ~10 new app IDs per week
- no push notifications, no in-app purchase

Apple's own route to that (Xcode "Personal Team") is **Mac-only**, so
Codemagic cannot drive it. On Windows the equivalent is
[Sideloadly](https://sideloadly.io) or
[AltStore](https://altstore.io) + AltServer, which sign an `.ipa` with
your free Apple ID and install it over USB. AltStore's desktop companion
re-signs automatically over Wi-Fi before the 7 days lapse; Sideloadly is
manual.

**So: free is genuinely enough to test Dana on your own iPhone.** The
$99 becomes unavoidable the moment you want *someone else's* phone —
a teacher, an admin, the centre — to install it.

---

## Part 1 — Free path: unsigned build → sideload

### What you must provide

1. A **Codemagic account** — sign in with GitHub, free.
2. **Authorise Codemagic on `github.com/azimalptech/dana`.** It is public,
   so this is one click.
3. Nothing else. No Apple ID, no certificate, no card.

### Steps

1. codemagic.io → **Add application** → GitHub → `azimalptech/dana`.
2. Pick **"I have a codemagic.yaml"** — the file is already committed at
   the repo root.
3. Choose the **`ios-unsigned`** workflow → **Start new build**.
4. ~10–20 min later, download **`Dana-unsigned.ipa`** from the artifacts.
5. On Windows: install [Sideloadly](https://sideloadly.io) (it needs the
   **desktop** iTunes from apple.com, *not* the Microsoft Store build),
   plug in the iPhone, drop the `.ipa` in, enter your free Apple ID.
6. On the iPhone: **Settings → General → VPN & Device Management** →
   trust your developer certificate. The app then opens.

Re-sideload every 7 days, or use AltStore so it refreshes itself.

> A free Codemagic personal account gets **500 macOS M2 minutes/month**
> (reset on the 1st, 1 concurrent build). A Flutter iOS build is roughly
> 10–20 minutes, so that is ~25–50 builds a month. `instance_type` is
> pinned to `mac_mini_m2` in the YAML because it is the only machine free
> accounts may use.

---

## Part 2 — Paid path: TestFlight / App Store

Only needed when other people must install it.

### What you must provide

| # | Item | Where to get it |
|---|---|---|
| 1 | **Apple Developer Program membership**, $99/yr | [developer.apple.com/programs/enroll](https://developer.apple.com/programs/enroll/). *Individual* is fastest. *Organization* needs a **D-U-N-S number** and takes days-to-weeks — start early if the centre must be the legal publisher. |
| 2 | **App Store Connect API key**: Issuer ID, Key ID, and the `.p8` file | App Store Connect → Users and Access → Integrations → App Store Connect API → **+**. Give it **App Manager** access. The **`.p8` downloads exactly once** — save it immediately. The first key must be created by the Account Holder. |
| 3 | That key **added to Codemagic** | Codemagic → settings → Integrations → Apple Developer Portal. Name the integration **`dana_app_store`** — the YAML references that exact name. |
| 4 | A **final bundle identifier** | Currently `com.dana.danaApp` on iOS vs `com.dana.dana_app` on Android. **Decide before creating the App Store Connect record — a bundle ID can never be changed afterwards, only abandoned for a new app.** Given the `mydana.app` domain, `app.mydana` would be more coherent than either. |
| 5 | An **app record** in App Store Connect + its numeric Apple ID | appstoreconnect.apple.com → Apps → **+**. Codemagic will not create this. Store the numeric id as the encrypted variable `APP_STORE_APPLE_ID`. |

You do **not** need a Mac, Xcode, Keychain Access, or a CSR — the
`ios-release` workflow generates the certificate and profile through the
API key.

### Then

Push a tag: `git tag v0.1.0 && git push origin v0.1.0`. The
`ios-release` workflow builds, signs, and uploads to TestFlight.
App Store submission is deliberately left manual.

---

## What was fixed to make iOS viable

An audit of `app/ios` before the first build found and fixed:

| Fix | Why it mattered |
|---|---|
| `tts.dart` sets an iOS audio session (`playback` + duck/mix) | **Real bug.** Without a category, TTS inherits `ambient`, which the iPhone's **Ring/Silent switch mutes** — a student with the switch flipped would tap the speaker (FR-13.24) and hear nothing, with no error. Android has no equivalent switch, so this never showed in testing. |
| `TARGETED_DEVICE_FAMILY` `"1,2"` → `"1"` | The project claimed iPad support, but the runtime portrait lock does not hold on iPad and the design is phone-shaped. Shipping iPad would invite a layout rejection. |
| Info.plist orientations → portrait only, `~ipad` array removed | Matched the declared capability to `SystemChrome` and the Android manifest. |
| `ITSAppUsesNonExemptEncryption = false` added | Without it every TestFlight upload halts at "Missing Compliance" until answered by hand. |
| `ASSETCATALOG_COMPILER_GENERATE_SWIFT_ASSET_SYMBOL_EXTENSIONS = AppIcon` → `YES` | A corrupted value in two of three configs — that setting is a boolean; `AppIcon` looks like a bad find/replace. |
| LaunchScreen background white → `#7C0C3E` | Same white-flash-then-jump the Android splash had. |
| Flutter pinned to **3.44.8** in CI | `AppDelegate.swift` uses `FlutterImplicitEngineDelegate` and `SceneDelegate` subclasses `FlutterSceneDelegate` — recent APIs. An older CI default would not compile. |

### Still open (deliberately not changed)

- **Four different product names**: Info.plist `Dana App`, `CFBundleName`
  `dana_app`, Android `Dana`, `MaterialApp(title:)` `Dana`, About box
  `mydana`. Not a build or review failure, but pick one — `mydana`
  matches the domain and the splash — before the App Store record exists.
- **`ios/Podfile` is not committed**, so pod resolution is unpinned. The
  `ios-unsigned` workflow exports the generated `Podfile` /
  `Podfile.lock` as artifacts; commit them to pin it.
- **`api.dart` still defaults to `http://127.0.0.1:8080`** when no
  `--dart-define` is passed. The workflows always pass it, so this only
  bites a hand-run build.
