"""Ingestion service entrypoint.

FastAPI app exposing `/health` and (from Task 10 onward) the MQTT subscriber
lifecycle. `create_app` is a factory so tests can inject a `Settings` without
depending on ambient environment variables.
"""

from collections.abc import AsyncIterator
from contextlib import asynccontextmanager

from fastapi import FastAPI

from .config import Settings
from .db import Database


def create_app(settings: Settings | None = None) -> FastAPI:
    settings = settings or Settings()

    @asynccontextmanager
    async def lifespan(app: FastAPI) -> AsyncIterator[None]:
        db = Database(settings)
        await db.open()
        app.state.db = db
        try:
            yield
        finally:
            await db.close()

    app = FastAPI(title="iiot ingestion service", version="0.1.0", lifespan=lifespan)

    @app.get("/health")
    async def health() -> dict[str, str]:
        connected = await app.state.db.ping()
        return {"status": "ok", "db": "connected" if connected else "unavailable"}

    return app


app = create_app()
