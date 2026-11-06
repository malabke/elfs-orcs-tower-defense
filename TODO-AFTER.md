# TODO AFTER: on hold (David, 2026-11-04)

Put aside for now; to pick up later.

## Signed apps

- desktop apps and games: Authenticode (Windows), Developer ID + notarization (macOS) with the developer's
  certificates (the exported executables, the installers, the IDE)

## Apple apps: signing, TestFlight, the App Store (needs the Apple developer account)

`bin/jumphp apple <game>` (docs/apple.md) builds the macOS app (.dmg, Apple silicon + Intel), the iPhone / iPad
app (.ipa) and the simulator's app on GitHub's Macs, and tries them there; they are signed ad hoc. With the account
(99 $ a year):

- macOS: Developer ID certificate in the repository's secrets, `codesign --options runtime` + `notarytool` +
  `stapler` in the workflow: opens without the right click
- iOS: distribution certificate + provisioning profile (or automatic signing with an App Store Connect API key),
  the .ipa signed, uploaded to TestFlight (`xcrun altool --upload-app` / the App Store Connect API), then the store
  listing (screenshots: the simulator's, as check.sh takes them)
- a playground "Apple" section (bundle id, as the Android one) when the App Store is reached
