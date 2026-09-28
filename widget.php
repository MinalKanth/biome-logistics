
<!-- ===================== Floating Buttons & Call Bar ===================== -->

    <style>
      
        @keyframes pulse-whatsapp {
            0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(37, 211, 102, 0.7); }
            70% { transform: scale(1.05); box-shadow: 0 0 0 10px rgba(37, 211, 102, 0); }
            100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(37, 211, 102, 0); }
        }
        
        .whatsapp-float-btn {
            position: fixed;
            bottom: 30px;
            left: 30px;
            width: 60px;
            height: 60px;
            background-color: #25d366;
            color: white;
            border-radius: 50%;
            text-decoration: none;
            font-size: 30px;
            z-index: 1040;
            animation: pulse-whatsapp 2s infinite;
        }

        .whatsapp-float-btn:hover {
            background-color: #128c7e;
            color: white;
        }

        .back-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 1040;
            width: 50px;
            height: 50px;
        }

    
        #mobileCallBar {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            padding: 10px;
            z-index: 1045;  
            background: rgba(255, 255, 255, 0.85); 
            backdrop-filter: blur(12px); 
            -webkit-backdrop-filter: blur(12px); 
            border-top: 1px solid rgba(255, 255, 255, 0.3);
        }

      
        @media (max-width: 767px) {
            body {
                padding-bottom: 70px; 
            }
        }
    </style>

     <!-- ===================== PREMIUM   WHATSAPP WIDGET ===================== -->

    <style>
   
        .wa-widget-container {
            position: fixed;
            bottom: 30px;
            left: 30px;
            z-index: 1050;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

       
        .wa-chat-box {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.5);
            border-radius: 16px;
            width: 320px;
            margin-bottom: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            transform: translateY(20px) scale(0.95);
            opacity: 0;
            visibility: hidden;
            transform-origin: bottom left;
            transition: all 0.4s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }

        .wa-chat-box.is-active {
            transform: translateY(0) scale(1);
            opacity: 1;
            visibility: visible;
        }

 
        .wa-chat-header {
            background: linear-gradient(135deg, #198754, #126b40);
            padding: 15px;
            border-radius: 16px 16px 0 0;
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .wa-bot-avatar {
            width: 40px;
            height: 40px;
            background: white;
            color: #198754;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
        }

      
        .wa-chat-body {
            padding: 20px 15px;
            background: url('https://www.transparenttextures.com/patterns/cubes.png');  
        }

        .wa-message-bubble {
            background: white;
            color: #333;
            padding: 12px 16px;
            border-radius: 0 12px 12px 12px;
            font-size: 14px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            display: none;  
            animation: fadeIn 0.5s ease forwards;
        }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }

     
        .wa-typing {
            display: flex;
            align-items: center;
            background: white;
            width: fit-content;
            padding: 12px 16px;
            border-radius: 0 12px 12px 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        .wa-dot {
            width: 6px;
            height: 6px;
            background: #bbb;
            border-radius: 50%;
            margin: 0 2px;
            animation: waBlink 1.4s infinite both;
        }
        .wa-dot:nth-child(1) { animation-delay: -0.32s; }
        .wa-dot:nth-child(2) { animation-delay: -0.16s; }
        @keyframes waBlink { 0%, 80%, 100% { opacity: 0.2; transform: scale(0.8); } 40% { opacity: 1; transform: scale(1.2); } }

       
        .wa-chat-footer {
            padding: 15px;
            border-top: 1px solid rgba(0,0,0,0.05);
            background: rgba(255,255,255,0.5);
            border-radius: 0 0 16px 16px;
        }

       
        .wa-float-btn {
            width: 60px;
            height: 60px;
            background: linear-gradient(135deg, #25D366, #128C7E);
            color: white;
            border-radius: 50%;
            border: none;
            font-size: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            position: relative;
            transition: all 0.3s ease;
            box-shadow: 0 8px 25px rgba(37, 211, 102, 0.4);
        }
        .wa-float-btn:hover {
            transform: scale(1.08) rotate(-10deg);
        }
        
 
        .online-dot {
            position: absolute;
            top: 2px;
            right: 2px;
            width: 14px;
            height: 14px;
            background: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .online-dot::after {
            content: '';
            width: 10px;
            height: 10px;
            background: #25D366;
            border-radius: 50%;
            animation: pulse-dot 2s infinite;
        }
        @keyframes pulse-dot { 0% { box-shadow: 0 0 0 0 rgba(37,211,102,0.7); } 70% { box-shadow: 0 0 0 6px rgba(37,211,102,0); } 100% { box-shadow: 0 0 0 0; } }

    </style>

  
    <div class="wa-widget-container d-none d-md-flex">
        
     
        <div class="wa-chat-box" id="waChatBox">
            <div class="wa-chat-header">
                <div class="d-flex align-items-center">
                    <div class="wa-bot-avatar">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div class="ms-3">
                        <h6 class="mb-0 fw-bold">Biome   Assistant</h6>
                        <small style="font-size: 11px; opacity: 0.9;"><i class="fas fa-circle text-warning me-1" style="font-size: 8px;"></i>Online & Ready</small>
                    </div>
                </div>
                <button class="btn-close btn-close-white" style="font-size: 12px;" id="closeWaChat"></button>
            </div>
            
            <div class="wa-chat-body">
               
                <div class="wa-typing" id="waTyping">
                    <div class="wa-dot"></div><div class="wa-dot"></div><div class="wa-dot"></div>
                </div>
                
                <div class="wa-message-bubble" id="waMessage">
                    <strong>Hi there! 👋</strong><br>
                    Looking for reliable logistics or premium bamboo? Let me know how I can help you today!
                </div>
            </div>

            <div class="wa-chat-footer">
                <a href="https://wa.me/919678431656?text=Hi%20Biome%20Enterprises,%20I%20would%20like%20to%20know%20more%20about%20your%20services." target="_blank" class="btn btn-success w-100 rounded-pill fw-bold d-flex align-items-center justify-content-center" style="background: #25D366; border:none;">
                    <i class="fab fa-whatsapp fs-5 me-2"></i> Start Chat
                </a>
            </div>
        </div>

        <!-- Floating Action Button -->
        <button class="wa-float-btn" id="waFloatBtn" aria-label="Open Chat">
            <i class="fab fa-whatsapp"></i>
            <div class="online-dot"></div>
        </button>

    </div>

 
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const waBtn = document.getElementById('waFloatBtn');
        const waBox = document.getElementById('waChatBox');
        const waClose = document.getElementById('closeWaChat');
        const waTyping = document.getElementById('waTyping');
        const waMessage = document.getElementById('waMessage');
        let isFirstOpen = true;

       
        function triggerChatbot() {
            waBox.classList.toggle('is-active');
            
            if (waBox.classList.contains('is-active') && isFirstOpen) {
                
                setTimeout(() => {
                    waTyping.style.display = 'none';
                    waMessage.style.display = 'block';
                    isFirstOpen = false;
                }, 1800);
            }
        }

 
        waBtn.addEventListener('click', triggerChatbot);
 
        waClose.addEventListener('click', () => {
            waBox.classList.remove('is-active');
        });
 
        if(window.innerWidth > 767) {
            setTimeout(() => {
                if(!waBox.classList.contains('is-active') && isFirstOpen) {
                    triggerChatbot();
                }
            }, 4000);
        }
    });
    </script>
    
    <!-- ===================== PREMIUM AI-STYLE WHATSAPP WIDGET END ===================== -->
    
    
    

    <!-- Back to Top (Hidden on Mobile, Visible on Desktop) -->
    <!-- d-none d-md-flex ensures it is completely invisible on mobile -->
    <a href="#" class="btn btn-primary rounded-circle shadow-lg back-to-top d-none d-md-flex align-items-center justify-content-center" style="opacity: 0; visibility: hidden; transition: all 0.3s ease-in-out;">
        <i class="bi bi-arrow-up fs-4"></i>
    </a>

    <!-- Sticky Mobile Call Bar (Visible on Mobile, Hidden on Desktop) -->
    <div id="mobileCallBar" class="d-flex d-md-none shadow-lg">
        <a href="tel:+919678431656" class="btn btn-primary flex-fill rounded-pill fw-bold mx-1 d-flex align-items-center justify-content-center">
            <i class="fa fa-phone me-2"></i>Call Now
        </a>
        <a href="https://wa.me/919678431656" target="_blank" class="btn btn-success flex-fill rounded-pill fw-bold mx-1 d-flex align-items-center justify-content-center">
            <i class="fab fa-whatsapp me-2"></i>WhatsApp
        </a>
    </div>
