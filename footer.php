<style>
    .footer {
        background: #ffffff;
        border-top: 1px solid #e1e4e8;
        padding: 14px 20px;
        margin-top: 30px;
        border-radius: 10px;
        text-align: center;
        width: 100%;
    }
    .footer-content {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        flex-wrap: wrap;
        font-size: 14px;
        color: #57606a;
        direction: rtl;
    }
    .footer-content a {
        color: #0969da;
        text-decoration: none;
        font-weight: 500;
        transition: color 0.2s ease;
    }
    .footer-content a:hover {
        color: #0550b3;
        text-decoration: underline;
    }
    .footer-divider {
        color: #d0d7de;
    }
    .footer-version {
        font-size: 12px;
        color: #8b949e;
        background: #f6f8fa;
        padding: 2px 12px;
        border-radius: 12px;
    }
    @media (max-width: 480px) {
        .footer {
            padding: 12px 16px;
        }
        .footer-content {
            font-size: 12px;
            flex-direction: column;
            gap: 6px;
        }
        .footer-divider {
            display: none;
        }
    }
</style>