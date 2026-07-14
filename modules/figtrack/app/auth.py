"""Validation des JWT SciencesWiki (clé publique partagée) + hiérarchie des rôles.

Le module ne signe JAMAIS de jeton : il valide ceux émis par SW. La hiérarchie des rôles est
RÉPLIQUÉE (le JWT ne porte que les rôles déclarés, ex. admin = [ROLE_USER, ROLE_ADMIN]).
"""

from __future__ import annotations

import jwt
from fastapi import HTTPException, Request

from .config import JWT_PUBLIC_KEY_PATH

_HIERARCHY = {
    "ROLE_ADMIN": ["ROLE_MODERATEUR", "ROLE_COMITE", "ROLE_RESEARCHER", "ROLE_TEACHER"],
    "ROLE_MODERATEUR": ["ROLE_REDACTEUR"],
    "ROLE_COMITE": ["ROLE_REDACTEUR"],
    "ROLE_REDACTEUR": ["ROLE_AUTEUR"],
    "ROLE_RESEARCHER": ["ROLE_AUTEUR"],
    "ROLE_TEACHER": ["ROLE_STUDENT"],
    "ROLE_STUDENT": ["ROLE_USER"],
    "ROLE_AUTEUR": ["ROLE_USER"],
}

_public_key: str | None = None


def _key() -> str:
    global _public_key
    if _public_key is None:
        with open(JWT_PUBLIC_KEY_PATH, "r", encoding="utf-8") as fh:
            _public_key = fh.read()
    return _public_key


def _expand(roles: list[str]) -> set[str]:
    seen: set[str] = set()
    stack = list(roles)
    while stack:
        role = stack.pop()
        if role in seen:
            continue
        seen.add(role)
        stack.extend(_HIERARCHY.get(role, []))
    return seen


class CurrentUser:
    def __init__(self, username: str, roles: set[str]):
        self.username = username
        self.roles = roles

    def has(self, role: str) -> bool:
        return role in self.roles


def current_user(request: Request) -> CurrentUser:
    auth = request.headers.get("authorization", "")
    if not auth.startswith("Bearer "):
        raise HTTPException(status_code=401, detail="Non authentifié.")
    try:
        payload = jwt.decode(auth[7:], _key(), algorithms=["RS256"])
    except Exception as exc:  # noqa: BLE001
        raise HTTPException(status_code=401, detail="Jeton invalide.") from exc
    username = str(payload.get("username") or payload.get("sub") or "")
    roles = _expand([str(r) for r in payload.get("roles", [])])
    return CurrentUser(username, roles)


def require_analyst(request: Request) -> CurrentUser:
    """Accès « analyste » : chercheur ou comité (admin inclus par hiérarchie)."""
    user = current_user(request)
    if not (user.has("ROLE_RESEARCHER") or user.has("ROLE_COMITE")):
        raise HTTPException(status_code=403, detail="Accès réservé (chercheur / comité).")
    return user
