#!/usr/bin/env bash
#
# release.sh — commit + tag semver + push d'une lib qcdgone
# Se place où tu veux dans le repo (racine ou bin/), il se repère seul.
#
# Usage :
#   ./release.sh patch "fix: refresh token expiré"
#   ./release.sh minor "feat: support du logout centralisé"
#   ./release.sh major "BC: renomme SsoClient::authenticate()"
#   ./release.sh 2.0.3 "release manuelle"
#
# Options :
#   --no-check   saute composer validate / phpstan / phpunit
#   --dry-run    affiche ce qui serait fait, ne pousse rien
#
set -euo pipefail

MAIN_BRANCH="main"
TAG_PREFIX="v"
RUN_CHECKS=1
DRY_RUN=0

if [ -t 1 ]; then R=$'\e[31m'; G=$'\e[32m'; Y=$'\e[33m'; B=$'\e[1m'; N=$'\e[0m'
else R=""; G=""; Y=""; B=""; N=""; fi
die()  { echo "${R}x $*${N}" >&2; exit 1; }
info() { echo "${B}>${N} $*"; }
ok()   { echo "${G}*${N} $*"; }

ARGS=()
for a in "$@"; do
  case "$a" in
    --no-check) RUN_CHECKS=0 ;;
    --dry-run)  DRY_RUN=1 ;;
    *)          ARGS+=("$a") ;;
  esac
done
set -- "${ARGS[@]:-}"

BUMP="${1:-patch}"
MESSAGE="${2:-}"
[ -n "$MESSAGE" ] || die "Message manquant.
  Usage : $0 {patch|minor|major|X.Y.Z} \"message\""

# ── racine du dépôt, quel que soit l'emplacement du script ───────────────────
ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || die "Pas dans un dépôt git."
cd "$ROOT"

[ -f composer.json ] || die "composer.json introuvable à la racine du dépôt."
PKG="$(php -r 'echo json_decode(file_get_contents("composer.json"),true)["name"] ?? "";')"
[ -n "$PKG" ] || die "Clé \"name\" absente du composer.json."

info "Package : ${B}$PKG${N}  (${ROOT})"

# ── garde-fous ────────────────────────────────────────────────────────────────
BRANCH="$(git rev-parse --abbrev-ref HEAD)"
[ "$BRANCH" = "$MAIN_BRANCH" ] || die "Tu es sur '$BRANCH'. Les releases se font depuis '$MAIN_BRANCH'.
  git checkout $MAIN_BRANCH"

info "Synchronisation avec origin…"
git fetch origin --tags --prune --prune-tags --quiet

if git rev-parse -q --verify "origin/$MAIN_BRANCH" >/dev/null; then
  BEHIND="$(git rev-list --count "HEAD..origin/$MAIN_BRANCH")"
  [ "$BEHIND" -eq 0 ] || die "Branche en retard de $BEHIND commit(s) sur origin.
  git pull --rebase"
fi

# ── calcul de la version ──────────────────────────────────────────────────────
LAST_TAG="$(git tag -l "${TAG_PREFIX}[0-9]*.[0-9]*.[0-9]*" --sort=-v:refname | head -n1 || true)"
LAST_TAG="${LAST_TAG:-${TAG_PREFIX}0.0.0}"
IFS=. read -r MA MI PA <<< "${LAST_TAG#$TAG_PREFIX}"

case "$BUMP" in
  major) MA=$((MA+1)); MI=0; PA=0 ;;
  minor) MI=$((MI+1)); PA=0 ;;
  patch) PA=$((PA+1)) ;;
  [0-9]*.[0-9]*.[0-9]*) IFS=. read -r MA MI PA <<< "$BUMP" ;;
  *) die "Bump invalide : '$BUMP' (attendu : patch, minor, major ou X.Y.Z)" ;;
esac

NEW_TAG="${TAG_PREFIX}${MA}.${MI}.${PA}"
git rev-parse -q --verify "refs/tags/$NEW_TAG" >/dev/null && die "Le tag $NEW_TAG existe déjà."

echo
echo "  ${LAST_TAG}  ->  ${G}${B}${NEW_TAG}${N}   ($BUMP)"
echo "  $MESSAGE"
echo

# ── contrôles qualité ─────────────────────────────────────────────────────────
if [ "$RUN_CHECKS" -eq 1 ]; then
  info "Contrôles…"
  composer validate --strict --no-check-publish --quiet || die "composer.json invalide."

  # ⚠ Un test qui existe mais que personne ne joue est PIRE que pas de test :
  # il donne la tranquillité sans la vérification. Les bundles n'ont ni `vendor/`
  # ni `composer.lock` (parti pris : c'est une bibliothèque). Sans ce garde-fou,
  # `[ -x vendor/bin/phpunit ]` est simplement faux, bash n'interrompt pas une
  # AND-list dont la partie gauche échoue — même sous `set -e` — et la
  # publication passe EN SILENCE, en affichant « Contrôles OK ».
  if [ -n "$(find . -maxdepth 2 -type d -name tests -not -path './vendor/*' -print -quit)" ]; then
    [ -x vendor/bin/phpunit ] || die "Des tests existent, mais vendor/bin/phpunit est absent.
  composer install"
  fi

  [ -x vendor/bin/phpstan ] && { vendor/bin/phpstan analyse --no-progress -q || die "PHPStan en échec."; }
  # ⚠ `--no-output` masquait la CAUSE de l'échec : on apprenait qu'un test cassait,
  # jamais lequel — et le tag ne partait pas sans qu'on sache quoi corriger.
  [ -x vendor/bin/phpunit ] && { vendor/bin/phpunit --colors=never || die "Tests en échec."; }
  ok "Contrôles OK"
fi

# ── commit + tag + push ───────────────────────────────────────────────────────
if [ "$DRY_RUN" -eq 1 ]; then
  echo "${Y}[dry-run]${N} rien n'a été poussé. État courant :"
  git status --short
  exit 0
fi

if [ -n "$(git status --porcelain)" ]; then
  git add -A
  git commit -q -m "$MESSAGE"
  ok "Commit créé"
else
  info "Rien à committer, on tague HEAD."
fi

git tag -a "$NEW_TAG" -m "$MESSAGE"
git push --quiet origin "$MAIN_BRANCH" --follow-tags
ok "Poussé : $MAIN_BRANCH + $NEW_TAG"

echo
echo "  Côté applications :"
echo "    composer update $PKG"
echo "  Contrainte recommandée :"
echo "    \"$PKG\": \"^${MA}.${MI}\""