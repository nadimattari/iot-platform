"""Structured asyncpg connection pool to the `iiot` database.

The pool is created lazily at app startup; a failed initial connection is
logged but does not prevent the service from booting. `/health` reports the
connection state so a scheduler/healthcheck can see it.
"""

import logging

import asyncpg

from .config import Settings

logger = logging.getLogger(__name__)


class Database:
    """Owns the asyncpg pool used by the ingestion worker."""

    def __init__(self, settings: Settings) -> None:
        self._settings = settings
        self._pool: asyncpg.Pool | None = None

    @property
    def pool(self) -> asyncpg.Pool | None:
        return self._pool

    async def open(self) -> None:
        try:
            self._pool = await asyncpg.create_pool(
                host=self._settings.pg_host,
                port=self._settings.pg_port,
                user=self._settings.pg_user,
                password=self._settings.pg_password,
                database=self._settings.pg_database,
                min_size=self._settings.pool_min_size,
                max_size=self._settings.pool_max_size,
            )
        except Exception:
            logger.warning("postgres connection failed; db will report unavailable", exc_info=True)
            self._pool = None

    async def close(self) -> None:
        if self._pool is not None:
            await self._pool.close()
            self._pool = None

    async def ping(self) -> bool:
        pool = self._pool
        if pool is None:
            return False
        try:
            async with pool.acquire() as conn:
                await conn.fetchval("SELECT 1")
            return True
        except Exception:
            logger.warning("db ping failed", exc_info=True)
            return False
