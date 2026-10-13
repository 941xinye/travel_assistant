# PostGIS 16 + pgvector（空间数据 + 向量检索，ARCHITECTURE.md 2.4）
# pgvector 通过 PGDG 官方 apt 包 postgresql-16-pgvector 安装
FROM postgis/postgis:16-3.4

RUN apt-get update \
    && apt-get install -y --no-install-recommends postgresql-16-pgvector \
    && rm -rf /var/lib/apt/lists/*
