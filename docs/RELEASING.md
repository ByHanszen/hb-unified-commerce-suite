# Releasing — HB Unified Commerce Suite

Doel: consistente releases via GitHub tags, zodat productie kan updaten via de WordPress plugin **Git Updater**.

## 1) Repo op GitHub
- Maak een repo aan (bij voorkeur): `hb-unified-commerce-suite`
- Push de plugin root (de map waarin `hb-unified-commerce-suite.php` staat) naar de root van de repo.

Aanbevolen structuur:
- `hb-unified-commerce-suite.php`
- `src/`
- `uninstall.php`
- `CHANGELOG.md`

## 2) Versie bump
Bij elke wijziging die je wilt uitrollen:
1. Update de versie in `hb-unified-commerce-suite.php`:
   - Plugin header `Version: X.Y.Z`
   - Constante `HB_UCS_VERSION` = `X.Y.Z`
2. Update `CHANGELOG.md`:
   - Voeg of werk de sectie `[X.Y.Z]` bij
3. Doe dit voor iedere functionele codewijziging, ook bij kleine admin- of bugfixes, zodat GitHub-updaters altijd een hogere detecteerbare versie zien.

## 3) Tag + release
Maak een tag met exact dezelfde versie als de plugin:

```bash
git add -A
git commit -m "Release X.Y.Z"
git push

git tag -a X.Y.Z -m "X.Y.Z"
git push --tags
```

Maak daarna een GitHub Release op basis van die tag (Release notes kun je uit `CHANGELOG.md` kopiëren).

## 4) Productie update via Git Updater
1. Installeer **Git Updater** op productie.
2. (Private repo) Voeg een GitHub token toe in Git Updater settings.
3. Ga naar Plugins → Updates en voer update uit.

## Troubleshooting
- Ziet Git Updater geen updates? Controleer:
  - Tag naam = plugin versie (exact)
  - Repo bevat plugin op root (geen extra submap in de release zip)
  - Caching: probeer WP cache te legen of update check opnieuw.
