# Historical data retention

The condominium book and identity records are treated as historical/audit data.

Hard deletion of a `Person`, `Unit`, `User`, `UnitRelation`, household membership, absence, animal registration or condominium-book declaration must not cascade into related history. Operational removal is represented by existing deactivation/end-date workflows instead.

Database foreign keys for these relationships therefore use `RESTRICT`. An unreviewed declaration may still have a nullable reviewer; nullability does not imply that a recorded reviewer may be deleted after review.
