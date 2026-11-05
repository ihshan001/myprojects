//
//  QueueDone.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-09.
//

import SwiftUI

struct QueueDone: View {
   
 
    
    var body: some View {
        ZStack {
            
        
            Image("welcome_bg")
                .resizable()
                .scaledToFill()
                .frame(height: 600)
                .frame(width: 450)
         
            VStack(spacing: 32) {
                
                Spacer()
                
         
                VStack(spacing: 16) {
                  
                    
                  
                    Image(systemName:  "checkmark.circle.fill" )
                        .font(.system(size: 100))
                        .foregroundColor( .white)
                }
                
      
                VStack(spacing: 12) {
                    Text("Your consultation has been completed successfully.")
                        .font(.system(size: 18, weight: .semibold))
                        .foregroundColor(.white)
                        .frame(maxWidth: .infinity, alignment: .center)
                        .multilineTextAlignment(.center)
                    
                   
                }
                
            
              
                
                Spacer()
                Spacer()
                Spacer()
            }
            .padding(.horizontal, 24)
        }
    }
}

#Preview {
    QueueDone()
}
