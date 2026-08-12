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

    # --- MQTT broker (Task 10) -------------------------------------------------
    mqtt_enabled: bool = True
    mqtt_host: str = Field("mqtt", validation_alias=AliasChoices("INGESTION_MQTT_HOST", "MQTT_HOST"))
    mqtt_port: int = Field(1883, validation_alias=AliasChoices("INGESTION_MQTT_PORT", "MQTT_PORT"))
    mqtt_username: str = Field(
        "ingestion", validation_alias=AliasChoices("INGESTION_MQTT_USERNAME", "MQTT_USERNAME")
    )
    mqtt_password: str = Field(
        "change-me", validation_alias=AliasChoices("INGESTION_MQTT_PASSWORD", "MQTT_PASSWORD")
    )

    # --- writer batching (Task 10) ----------------------------------------------
    write_batch_size: int = 500
    write_batch_timeout: float = 0.2
    write_queue_maxsize: int = 10000

    # --- Modbus TCP poller (Task 12) ---------------------------------------------
    modbus_enabled: bool = True
    modbus_reload_interval: float = 15.0
    modbus_default_host: str = ""
    modbus_default_port: int = 502
    modbus_default_unit_id: int = 1
    modbus_read_timeout: float = 5.0
    modbus_publish_timeout: float = 5.0

    # --- Mercure publisher (Task 18) ----------------------------------------
    mercure_hub_url: str = Field("http://caddy/.well-known/mercure", validation_alias=AliasChoices("MERCURE_HUB_URL"))
    mercure_publisher_jwt_key: str = Field("change-me", validation_alias=AliasChoices("MERCURE_PUBLISHER_JWT_KEY"))
