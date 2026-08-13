"""Structured asyncpg connection pool to the `iiot` database.

The pool is opened at app startup. If the initial connection fails (e.g. the
database is still booting or mid-migration) `open()` retries with a fixed
backoff instead of leaving the service running with a permanently dead pool,
which would silently drop every ingested point.
"""

import asyncio
import logging
from time import monotonic

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
        timeout = self._settings.pg_connect_timeout_secs
        deadline = None if timeout is None else monotonic() + timeout
        while self._pool is None:
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
                if deadline is not None and monotonic() >= deadline:
                    logger.warning(
                        "postgres unreachable after %.1fs; db will report unavailable",
                        timeout,
                        exc_info=True,
                    )
                    return
                logger.warning(
                    "postgres connection failed; retrying in %.1fs",
                    self._settings.pg_retry_interval_secs,
                    exc_info=True,
                )
                await asyncio.sleep(self._settings.pg_retry_interval_secs)

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
