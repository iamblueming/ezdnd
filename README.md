# ezdnd
Easy Drag &amp; Drop assest file uploader written in PHP. Mainly used for markdown image upload bc im lazy to use ftp every single time i write markdown with images

# deploy
```
webroot/
├── ezdnd/
│   └── index.php         ← the script below
└── ezassest/             ← uploads go here
    └── (auto-created subfolders like "myfolder")
```

# usage
https://ur.doma.in/ezdnd?token=<token-in-index.php>

# function(s)
- strips EXIF/GPS/device metadata by re-encoding images
- gives three link after upload - markdown,html,plain url

i made it just because i was too lazy to open filezilla everytime

mine is deployed at https://file.kr.md
if you want to use it, feel free to contact telegram @iamblueming and ask for token