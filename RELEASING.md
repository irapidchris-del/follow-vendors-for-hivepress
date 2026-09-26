# Releasing Follow Vendors for HivePress

The plugin updates itself from this repository's GitHub Releases through
WordPress's native `update_plugins_github.com` filter (WordPress 5.8+), with no
third-party library. See `includes/updater.php`. New versions appear on the
Plugins screen of every site running it.

`.github/workflows/release.yml` builds the release zip and attaches it.

## The fixed pieces

- **Version** lives in three places that must agree: the `Version:` header and
  `HPFV_VERSION` in `follow-vendors-for-hivepress.php`, and `Stable tag:` in `readme.txt`. Add the
  matching `= X.Y.Z =` changelog entry in `readme.txt`.
- **`Update URI`** (`https://github.com/irapidchris-del/follow-vendors-for-hivepress`) routes the
  update check to the updater. Do not change it.
- **The release asset** is always named `follow-vendors-for-hivepress.zip` and holds a single
  top-level `follow-vendors-for-hivepress/` folder. The workflow names it; never attach a
  versioned zip by hand.
- **The zip is built from the named paths on the workflow's `cp` lines.** A new
  top-level file or folder is missing from every release until it is added
  there.

## Releasing

1. Bump the version in all three places and add the changelog entry.
2. Push to `main` **first**. The workflow builds from the dispatched commit, so
   a release created before the push ships the old code under the new tag.
3. Run the workflow: `release.yml` on `main` with inputs `tag` (`vX.Y.Z`) and
   `notes` (Markdown). Publishing a release from the GitHub web page also runs
   it.
4. Check the release has the tag, the notes and exactly one `follow-vendors-for-hivepress.zip`
   asset, then download the permanent link below (a GET, not a HEAD request)
   and list the zip's contents.

Never re-run the workflow with an older tag: it moves the tag to the current
commit and rebuilds the asset from it.

## The permanent download link

```
https://github.com/irapidchris-del/follow-vendors-for-hivepress/releases/latest/download/follow-vendors-for-hivepress.zip
```

It always serves the newest published release. Drafts and pre-releases are
skipped.
