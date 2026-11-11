# Publishing the three apps to the Play Store

Apps: **customer** `qa.albahar.customer`, **driver** `qa.albahar.driver`, **branch staff** `qa.albahar.staff`.

## 1. The upload key (done once)

A 4096-bit RSA upload keystore (`albahar-upload.jks`, alias `albahar-upload`, valid until 2056) signs all three apps. It is **never committed**
(`android/.gitignore` ignores `*.jks` and `key.properties`). Keep the file and its passwords in a password manager and a second safe place:
Google Play App Signing holds the real app-signing key, so a lost *upload* key can be reset through Play support, but it takes days.

SHA-256 of the upload certificate (Play Console shows the same after your first upload):
`B1:08:AD:BC:FB:6E:B3:46:4A:3F:59:B3:5C:88:F2:17:E0:07:85:47:47:3E:6F:6E:F5:D6:97:91:CC:FC:A8:D1`

## 2. Give it to GitHub Actions (once)

Repo > Settings > Secrets and variables > Actions > New repository secret:

| Secret | Value |
|---|---|
| `ANDROID_KEYSTORE_BASE64` | the content of `albahar-upload.jks.base64.txt` (one line) |
| `ANDROID_KEYSTORE_PASSWORD` | the store password |
| `ANDROID_KEY_ALIAS` | `albahar-upload` |
| `ANDROID_KEY_PASSWORD` | the key password (same as the store password) |

Then run **Actions > Android APK > Run workflow**. Each app's release now contains a signed `.apk` (to test) and a signed `.aab` (to upload).
Release notes say `Signing: release key` when it worked.

## 3. Play Console

1. Create the app (one per package id above), enable **Play App Signing**, upload the `.aab` from the release (internal testing first).
2. Fill the store listing, content rating, data safety (the customer app collects name, phone, address, location, order history) and the privacy policy URL.
3. Account deletion: the customer app has it in Settings; Play also needs a public web page for it.
4. `versionCode` is the workflow run number, so every build is higher than the last.

Local signed build (optional): copy the keystore to `android/app/release.jks`, create `android/key.properties` with `storeFile`, `storePassword`, `keyAlias`, `keyPassword`, then
`flutter build appbundle --release --flavor customerProd --dart-define=FLAVOR=prod --dart-define=API_BASE_URL=https://albahar.atharqatar.net/api/v1`.
