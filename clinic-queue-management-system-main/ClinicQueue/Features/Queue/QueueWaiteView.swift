//
//  QueueWaiteView.swift
//  ClinicQueue
//
//  Created by Keshana Liyanaarachchi on 2026-03-09.
//

import SwiftUI

struct QueueWaiteView: View {

    var body: some View {
        ScrollView {
            ZStack {

                VStack(spacing: 32) {
                    QueueWaite(
                        queueNumber: "11",
                      
                    )
                    
                  
                }
                
                
                
            }
        }
        .ignoresSafeArea()
    }
}

#Preview {
    QueueWaiteView()
}
