# Moving product photos to the server

Product photos are no longer in git. They live in `storage/app/public/product-photos` and are served through the `public/storage` symlink.

One-time steps:

1. On this machine, after `php artisan products:import-builtin-photos --force` has run on your real database, zip the folder:
   `Compress-Archive -Path storage\app\public\product-photos -DestinationPath product-photos.zip` (PowerShell)
2. Upload `product-photos.zip` to the server (SFTP, cPanel file manager, scp) and unzip it so the files end up in
   `<project>/storage/app/public/product-photos/`
   (`unzip product-photos.zip -d storage/app/public/` creates the folder).
3. On the server, once: `php artisan storage:link` (creates `public/storage`). Make the folder writable by the web user.
4. Copy the database (or run `products:import-builtin-photos --force` there if it still has old-style rows; it needs the old `public/images/products` files, which git does deliver).
5. `git pull` never touches `storage/app/public/product-photos`. From now on a photo the admin uploads on the server goes straight to the server's storage and survives every deploy. Keep the folder in your server backups.
