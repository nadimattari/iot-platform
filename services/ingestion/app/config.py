"""Environment configuration for the ingestion service.

All values are read from the environment (or an optional `.env` file). The
standard `PG*` variables match the other services so a single shared
`deploy/.env` drives every container.
"""

from pydantic import AliasChoices, Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        extra="ignore",
        populate_by_name=True,
    )

    # --- PostgreSQL (`iiot` database) --------------------------------------
    pg_host: str = Field("localhost", validation_alias=AliasChoices("PGHOST", "PG_HOST"))
    pg_port: int = Field(5432, validation_alias=AliasChoices("PGPORT", "PG_PORT"))
    pg_user: str = Field("iiot", validation_alias=AliasChoices("PGUSER", "PG_USER"))
    pg_password: str = Field("change-me", validation_alias=AliasChoices("PGPASSWORD", "PG_PASSWORD"))
    pg_database: str = Field("iiot", validation_alias=AliasChoices("PGDATABASE", "PG_DATABASE"))

    # --- asyncpg pool --------------------------------------------------------
    pool_min_size: int = 1
    pool_max_size: int = 10

    # --- MQTT broker (used from Task 10) --------------------------------------
    mqtt_username: str = "ingestion"
    mqtt_password: str = "change-me"

    # --- Mercure publisher JWT key (used from Task 10) -------------------------
    mercure_publisher_jwt_key: str = "change-me"
